<?php

declare(strict_types=1);

$source = dirname(__DIR__, 2);
$releaseVersion = getenv('MODERNORM_RELEASE_VERSION') ?: '0.1.0-beta.3';
$directory = getenv('MODERNORM_RELEASE_DIR') ?: sys_get_temp_dir().'/modernorm-release-'.bin2hex(random_bytes(6));
if (file_exists($directory)) {
    throw new RuntimeException('Release output directory must not exist: '.$directory);
}
mkdir($directory, 0777, true);
mkdir($directory.'/dist');
mkdir($directory.'/consumer');
$composer = getenv('COMPOSER_BINARY') ?: 'composer';
if (!str_contains($composer, DIRECTORY_SEPARATOR)) {
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $path) {
        if (is_file($path.'/'.$composer) && is_executable($path.'/'.$composer)) {
            $composer = $path.'/'.$composer;
            break;
        }
    }
}
$execute = static function (array $command, ?string $cwd = null, array $environment = []): void {
    $process = proc_open($command, [STDIN, ['pipe', 'w'], ['redirect', 1]], $pipes, $cwd, array_merge(getenv(), $environment));
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start release check process.');
    }
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 8192);
        if ($chunk !== false) {
            fwrite(STDOUT, $chunk);
        }
    }
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Release check subprocess failed.');
    }
};
$run = static function (array $arguments, string $cwd, array $environment = []) use ($composer, $execute): void {
    $execute(array_merge([PHP_BINARY, $composer], $arguments), $cwd, $environment);
};
$run(['archive', '--format=zip', '--dir='.$directory.'/dist', '--file=modernorm-beta-candidate', '--no-interaction'], $source, ['COMPOSER_ROOT_VERSION' => $releaseVersion]);
$archive = $directory.'/dist/modernorm-beta-candidate.zip';
$zip = new ZipArchive();
if ($zip->open($archive) !== true) {
    throw new RuntimeException('Cannot open release archive.');
}
$files = [];
for ($index = 0; $index < $zip->numFiles; ++$index) {
    $name = $zip->getNameIndex($index);
    if (preg_match('~(?:^|/)(?:vendor|\.git|\.idea|\.phpunit\.cache)(?:/|$)|(?:^|/)\.env(?:\.[^/]*)?$~', $name)) {
        throw new RuntimeException('Local-only content in release archive: '.$name);
    }
    if (str_starts_with($name, 'src/') && str_ends_with($name, '.php')) {
        if (!is_file($source.'/'.$name) || hash('sha256', $zip->getFromIndex($index)) !== hash_file('sha256', $source.'/'.$name)) {
            throw new RuntimeException('Archive differs from working source: '.$name);
        }
    }
    $files[$name] = true;
}
foreach (['composer.json', 'LICENSE', 'licenses/Doctrine-ORM-MIT.txt', 'src/EntityManager.php'] as $required) {
    if (!isset($files[$required])) {
        throw new RuntimeException('Missing release file: '.$required);
    }
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source.'/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php' && !isset($files['src/'.substr($file->getPathname(), strlen($source.'/src/'))])) {
        throw new RuntimeException('Source missing from release archive: '.$file->getPathname());
    }
}
$archivedManifest = json_decode($zip->getFromName('composer.json'), true, 512, JSON_THROW_ON_ERROR);
$zip->close();
$manifest = json_decode(file_get_contents($source.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
if ($archivedManifest !== $manifest) {
    throw new RuntimeException('Packaged composer.json differs from the current manifest.');
}
$package = array_intersect_key($manifest, array_flip(['name', 'description', 'type', 'license', 'require', 'autoload', 'suggest', 'conflict']));
$package['version'] = $releaseVersion;
$package['dist'] = ['type' => 'zip', 'url' => 'file://'.$archive, 'shasum' => sha1_file($archive)];
$consumer = ['name' => 'modernorm/release-consumer', 'require' => [$manifest['name'] => $releaseVersion], 'repositories' => [['type' => 'package', 'package' => $package]]];
file_put_contents($directory.'/consumer/composer.json', json_encode($consumer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
$run(['install', '--no-dev', '--no-interaction', '--prefer-dist', '--no-progress'], $directory.'/consumer');
$smoke = static function (bool $cache) use ($source, $directory, $execute): void {
    $command = [PHP_BINARY, $source.'/tools/release/consumer-smoke.php', $directory.'/consumer/vendor/autoload.php'];
    if ($cache) {
        $command[] = '--with-cache';
    }
    $execute($command);
};
$smoke(false);
$run(['require', 'symfony/cache:^7.4', '--update-no-dev', '--no-interaction', '--prefer-dist', '--no-progress'], $directory.'/consumer');
$smoke(true);
file_put_contents($directory.'/report.json', json_encode(['candidate' => $package['version'], 'archive_sha256' => hash_file('sha256', $archive), 'source_files_verified' => count(array_filter(array_keys($files), static fn (string $file): bool => str_starts_with($file, 'src/') && str_ends_with($file, '.php'))), 'consumer_without_dev' => true, 'optional_l2' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
echo 'Release candidate verified: '.$directory."\n";
