<?php

/*
 * PhingPharBuilder
 *
 * (c) Wider Plan Ltd <development@widerplan.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WiderPlan\PhingPharBuilder;

use Symfony\Component\Finder\Finder;

/**
 * The Compiler class compiles Phing into a phar
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Chris Smith <chris.smith@widerplan.com>
 */
class Compiler
{
    /** @var string Path to root directory */
    private $rootPath;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->rootPath = dirname(dirname(dirname(__DIR__)));
    }

    /**
     * Compiles Phing into a single phar file
     *
     * @throws \RuntimeException
     * @param  string            $pharFile The full path to the file to create
     */
    public function compile($pharFile = 'phing.phar')
    {
        if (file_exists($pharFile)) {
            unlink($pharFile);
        }

        $phar = new \Phar($pharFile, 0, 'phing.phar');
        $phar->setSignatureAlgorithm(\Phar::SHA1);

        $phar->startBuffering();

        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->notName('Compiler.php')
            ->in(__DIR__)
        ;

        foreach ($finder as $file) {
            $this->addFile($phar, $file);
        }

        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->exclude('Tests')
            ->in($this->rootPath . '/vendor/composer')
            ->in($this->rootPath . '/vendor/pear')
        ;

        foreach ($finder as $file) {
            $this->addFile($phar, $file, true);
        }
        $this->addFile($phar, new \SplFileInfo($this->rootPath . '/vendor/autoload.php'), true);

        $finder = new Finder();
        $finder->files()
            ->name('*.php')
            ->ignoreVCS(true)
            ->exclude('test')
            ->exclude('docs')
            ->exclude('build')
            ->in($this->rootPath . '/vendor/phing')
        ;

        foreach ($finder as $file) {
            $this->addFile($phar, $file, true);
        }

        $finder = new Finder();
        $finder->files()
            ->notName('*.php')
            ->ignoreVCS(true)
            ->exclude('test')
            ->exclude('docs')
            ->exclude('build')
            ->in($this->rootPath . '/vendor/phing')
        ;

        foreach ($finder as $file) {
            $this->addFile($phar, $file, false);
        }

        // Stubs
        $phar->setStub($this->getStub());

        $phar->stopBuffering();

        $this->addFile($phar, new \SplFileInfo($this->rootPath . '/LICENSE'), false);

        unset($phar);
    }

    private function addFile($phar, $file, $strip = false)
    {
        $path = str_replace($this->rootPath.DIRECTORY_SEPARATOR, '', $file->getRealPath());

        $content = file_get_contents($file);
        if ($strip) {
            $content = $this->stripWhitespace($content);
        } elseif ('LICENSE' === basename($file)) {
            $content = "\n".$content."\n";
        }

        $phar->addFromString($path, $content);
    }

    /**
     * Removes whitespace from a PHP source string while preserving line numbers.
     *
     * @param  string $source A PHP string
     * @return string The PHP string with the whitespace removed
     */
    private function stripWhitespace($source)
    {
        if (!function_exists('token_get_all')) {
            return $source;
        }

        $output = '';
        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $output .= $token;
            } elseif (in_array($token[0], array(T_COMMENT, T_DOC_COMMENT))) {
                $output .= str_repeat("\n", substr_count($token[1], "\n"));
            } elseif (T_WHITESPACE === $token[0]) {
                // reduce wide spaces
                $whitespace = preg_replace('{[ \t]+}', ' ', $token[1]);
                // normalize newlines to \n
                $whitespace = preg_replace('{(?:\r\n|\r|\n)}', "\n", $whitespace);
                // trim leading spaces
                $whitespace = preg_replace('{\n +}', "\n", $whitespace);
                $output .= $whitespace;
            } else {
                $output .= $token[1];
            }
        }

        return $output;
    }

    private function getStub()
    {
        $stub = <<<'EOF'
#!/usr/bin/env php
<?php
/*
 * This file is part of PhingPharBuilder.
 *
 * (c) Wider Plan Ltd <development@widerplan.com>
 *
 * For the full copyright and license information, please view
 * the license that is located at the bottom of this file.
 */

EOF;

        return $stub . <<<'EOF'
ini_set('html_errors', 'off');

Phar::mapPhar('phing.phar');

putenv('PHING_HOME=phar://phing.phar/vendor/phing/phing');

set_include_path(implode(PATH_SEPARATOR, array(
    'phar://phing.phar/vendor/phing/phing/classes',
)));

if (
    !in_array('-logger', $argv) &&
    (
        (
            defined('PHP_WINDOWS_VERSION_BUILD') &&
            (
                false !== getenv('ANSICON') ||
                'ON' === getenv('ConEmuANSI')
            )
        ) ||
        (
            function_exists('posix_isatty') &&
            @posix_isatty(STDOUT)
        )
    )) {
    $argv[] = '-logger';
    $argv[] = 'phing.listener.AnsiColorLogger';
    $argc += 2;
}

$loader = require 'phar://phing.phar/vendor/autoload.php';

require 'phar://phing.phar/vendor/phing/phing/bin/phing.php';

__HALT_COMPILER();
EOF;
    }
}
