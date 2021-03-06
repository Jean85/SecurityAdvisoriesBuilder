<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

declare(strict_types=1);

namespace Roave\SecurityAdvisories;

use DateTime;
use DateTimeZone;
use ErrorException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

(function () {
    require_once __DIR__ . '/vendor/autoload.php';

    set_error_handler(
        function ($errorCode, $message = '', $file = '', $line = 0) {
            throw new ErrorException($message, 0, $errorCode, $file, $line);
        },
        E_STRICT | E_NOTICE | E_WARNING
    );

    $token                     = \getenv('GITHUB_TOKEN');
    $authentication            = $token ? $token . ':x-oauth-basic@' : '';
    $advisoriesRepository      = 'https://' . $authentication . 'github.com/FriendsOfPHP/security-advisories.git';
    $roaveAdvisoriesRepository = 'https://' . $authentication . 'github.com/Roave/SecurityAdvisories.git';
    $advisoriesExtension       = 'yaml';
    $buildDir                  = __DIR__ . '/build';
    $baseComposerJson          = [
        'name'        => 'roave/security-advisories',
        'type'        => 'metapackage',
        'description' => 'Prevents installation of composer packages with known security vulnerabilities: '
            . 'no API, simply require it',
        'license'     => 'MIT',
        'authors'     => [[
                              'name'  => 'Marco Pivetta',
                              'role'  => 'maintainer',
                              'email' => 'ocramius@gmail.com',
                          ]],
    ];

    $execute = function (string $commandString) : array {
        // may the gods forgive me for this in-lined command addendum, but I CBA to fix proc_open's handling
        // of exit codes.
        exec($commandString . ' 2>&1', $output, $result);

        if (0 !== $result) {
            throw new \UnexpectedValueException(sprintf(
                'Command failed: "%s" "%s"',
                $commandString,
                implode(PHP_EOL, $output)
            ));
        }

        return $output;
    };

    $cleanBuildDir = function () use ($buildDir, $execute) : void {
        $execute('rm -rf ' . escapeshellarg($buildDir));
        $execute('mkdir ' . escapeshellarg($buildDir));
    };

    $cloneAdvisories = function () use ($advisoriesRepository, $buildDir, $execute) : void {
        $execute(
            'git clone '
            . escapeshellarg($advisoriesRepository)
            . ' ' . escapeshellarg($buildDir . '/security-advisories')
        );
    };

    $cloneRoaveAdvisories = function () use ($roaveAdvisoriesRepository, $buildDir, $execute) : void {
        $execute(
            'git clone '
            . escapeshellarg($roaveAdvisoriesRepository)
            . ' ' . escapeshellarg($buildDir . '/roave-security-advisories')
        );

        $execute(\sprintf(
            'cp -r %s %s',
            escapeshellarg($buildDir . '/roave-security-advisories'),
            escapeshellarg($buildDir . '/roave-security-advisories-original')
        ));
    };

    /**
     * @param string $path
     *
     * @return Advisory[]
     */
    $findAdvisories = function (string $path) use ($advisoriesExtension) : array {
        $yaml = new Yaml();

        return array_map(
            function (SplFileInfo $advisoryFile) use ($yaml) {
                return Advisory::fromArrayData(
                    $yaml->parse(file_get_contents($advisoryFile->getRealPath()), true)
                );
            },
            iterator_to_array(new \CallbackFilterIterator(
                new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                ),
                function (SplFileInfo $advisoryFile) use ($advisoriesExtension) {
                    // @todo skip `vendor` dir
                    return $advisoryFile->isFile() && $advisoryFile->getExtension() === $advisoriesExtension;
                }
            ))
        );
    };

    /**
     * @param Advisory[] $advisories
     *
     * @return Component[]
     */
    $buildComponents = function (array $advisories) : array {
        // @todo need a functional way to do this, somehow
        $indexedAdvisories = [];
        $components        = [];

        foreach ($advisories as $advisory) {
            if (! isset($indexedAdvisories[$advisory->getComponentName()])) {
                $indexedAdvisories[$advisory->getComponentName()] = [];
            }

            $indexedAdvisories[$advisory->getComponentName()][] = $advisory;
        }

        foreach ($indexedAdvisories as $componentName => $advisories) {
            $components[$componentName] = new Component($componentName, $advisories);
        }

        return $components;
    };

    /**
     * @param Component[] $components
     *
     * @return string[]
     */
    $buildConflicts = function (array $components) : array {
        $conflicts = [];

        foreach ($components as $component) {
            $conflicts[$component->getName()] = $component->getConflictConstraint();
        }

        ksort($conflicts);

        return array_filter($conflicts);
    };

    $buildConflictsJson = function (array $baseConfig, array $conflicts) : string {
        return json_encode(
            array_merge(
                $baseConfig,
                ['conflict' => $conflicts]
            ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    };

    $writeJson = function (string $jsonString, string $path) : void {
        file_put_contents($path, $jsonString . "\n");
    };

    $runInPath = function (callable $function, string $path) {
        $originalPath = getcwd();

        chdir($path);

        try {
            $returnValue = $function();
        } finally {
            chdir($originalPath);
        }

        return $returnValue;
    };

    $getComposerPhar = function (string $targetDir) use ($runInPath, $execute) : void {
        $runInPath(
            function () use ($targetDir, $execute) : void {
                $installerPath = escapeshellarg($targetDir . '/composer-installer.php');

                $execute(sprintf(
                    'curl -sS https://getcomposer.org/installer -o %s && php %s',
                    $installerPath,
                    $installerPath
                ));
            },
            $targetDir
        );
    };

    $validateComposerJson = function (string $composerJsonPath) use ($runInPath, $execute) : void {
        $runInPath(
            function () use ($execute) {
                $execute('php composer.phar validate');
            },
            dirname($composerJsonPath)
        );
    };

    $copyGeneratedComposerJson = function (string $sourceComposerJsonPath, string $targetComposerJsonPath) use ($execute
    ) : void {
        $execute(\sprintf(
            'cp %s %s',
            \escapeshellarg($sourceComposerJsonPath),
            \escapeshellarg($targetComposerJsonPath)
        ));
    };

    $commitComposerJson = function (string $composerJsonPath) use ($runInPath, $execute) : void {
        $runInPath(
            function () use ($composerJsonPath, $execute) {
                $execute('git add ' . escapeshellarg(realpath($composerJsonPath)));

                $message = sprintf(
                    'Committing generated "composer.json" file as per "%s"',
                    (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::W3C)
                );

                $execute('git diff-index --quiet HEAD || git commit -m ' . escapeshellarg($message));
            },
            dirname($composerJsonPath)
        );
    };

// cleanup:
    $cleanBuildDir();
    $cloneAdvisories();
    $cloneRoaveAdvisories();

// actual work:
    $writeJson(
        $buildConflictsJson(
            $baseComposerJson,
            $buildConflicts(
                $buildComponents(
                    $findAdvisories($buildDir . '/security-advisories')
                )
            )
        ),
        __DIR__ . '/build/composer.json'
    );

    $getComposerPhar(__DIR__ . '/build');
    $validateComposerJson(__DIR__ . '/build/composer.json');

    $copyGeneratedComposerJson(
        __DIR__ . '/build/composer.json',
        __DIR__ . '/build/roave-security-advisories/composer.json'
    );
    $commitComposerJson(__DIR__ . '/build/roave-security-advisories/composer.json');
})();
