<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Support;

use Atoms\Cli\Process\ProcessResult;

/**
 * A {@see FakeProcessRunner} whose `composer install` materializes a canned
 * one-package vendor tree — class + function file + LICENSE + files that must
 * be pruned — with real Composer-shaped autoload output, in whatever cwd the
 * vendor stage hands it. The one fake every test that needs a full
 * (non---fast) build shares, so the canned tree's shape is defined once.
 */
final class CannedComposer
{
    /**
     * @param array<string, string> $extraFiles vendor-relative path => contents,
     *        written into the tree verbatim (for pruning/notice tests)
     */
    public static function runner(array $extraFiles = []): FakeProcessRunner
    {
        $runner = new FakeProcessRunner(onPath: ['composer' => '/usr/bin/composer']);
        $runner->resultFor = static function (array $command, ?string $cwd) use ($extraFiles): ?ProcessResult {
            if ($command[0] !== 'composer' || $cwd === null) {
                return null;
            }

            $v = $cwd . '/vendor';
            foreach ([$v . '/acme/lib/src', $v . '/composer'] as $dir) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($cwd . '/composer.lock', "{\"canned\": true}\n");
            file_put_contents(
                $v . '/acme/lib/src/Greeter.php',
                "<?php\n\nnamespace Acme\\Lib;\n\nfinal class Greeter\n{\n    public static function greet(): string\n    {\n        return 'hello from vendor';\n    }\n}\n",
            );
            file_put_contents(
                $v . '/acme/lib/functions.php',
                "<?php\n\nif (!function_exists('acme_vendor_greet')) {\n    function acme_vendor_greet(): string\n    {\n        return 'hello from a function file';\n    }\n}\n",
            );
            file_put_contents($v . '/acme/lib/LICENSE', "MIT\n");
            file_put_contents($v . '/acme/lib/readme.md', "not shipped\n");
            // Real optimized classmaps include Composer\InstalledVersions,
            // which points into vendor/composer/; the canned map mirrors that.
            file_put_contents(
                $v . '/composer/InstalledVersions.php',
                "<?php\n\nnamespace Composer;\n\nfinal class InstalledVersions\n{\n}\n",
            );
            file_put_contents($v . '/composer/installed.php', "<?php\n\nreturn [];\n");
            file_put_contents(
                $v . '/composer/autoload_classmap.php',
                "<?php\n\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\n\nreturn [\n    'Acme\\\\Lib\\\\Greeter' => \$vendorDir . '/acme/lib/src/Greeter.php',\n    'Composer\\\\InstalledVersions' => \$vendorDir . '/composer/InstalledVersions.php',\n];\n",
            );
            file_put_contents(
                $v . '/composer/autoload_files.php',
                "<?php\n\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\n\nreturn [\n    'deadbeef' => \$vendorDir . '/acme/lib/functions.php',\n];\n",
            );
            file_put_contents(
                $v . '/composer/installed.json',
                "{\"packages\": [{\"name\": \"acme/lib\", \"version\": \"1.2.3\"}]}\n",
            );
            file_put_contents($v . '/autoload.php', "<?php // composer runtime, must be pruned\n");

            foreach ($extraFiles as $relative => $contents) {
                $path = $v . '/' . $relative;
                if (!is_dir(\dirname($path))) {
                    mkdir(\dirname($path), 0777, true);
                }
                file_put_contents($path, $contents);
            }

            return new ProcessResult(0, '', '');
        };

        return $runner;
    }
}
