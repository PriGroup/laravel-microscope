<?php

namespace Imanghafoori\LaravelMicroscope\Psr4;

use Illuminate\Support\Facades\Event;
use Imanghafoori\Filesystem\Filesystem;
use Imanghafoori\LaravelMicroscope\Analyzers\ComposerJson;
use Imanghafoori\LaravelMicroscope\FileReaders\FilePath;
use Imanghafoori\LaravelMicroscope\LaravelPaths\LaravelPaths;

class ClassRefCorrector
{
    private static $onFix;

    public static function fixAllRefs($onFix)
    {
        self::$onFix = $onFix;
        if (CheckNamespaces::$changedNamespaces) {
            $olds = \array_keys(CheckNamespaces::$changedNamespaces);
            $news = \array_values(CheckNamespaces::$changedNamespaces);

            ClassRefCorrector::changeReferences($olds, $news);
        }
    }

    public static function changeReferences($olds, $news)
    {
        $olds = [$olds, self::possibleOccurrence($olds)];
        foreach (ComposerJson::readAutoload() as $autoload) {
            foreach ($autoload as $psr4Path) {
                $files = FilePath::getAllPhpFiles($psr4Path);
                foreach ($files as $classFilePath) {
                    $_path = $classFilePath->getRealPath();
                    self::fixAndReport($_path, $olds, $news);
                }
            }
        }

        foreach (LaravelPaths::collectNonPsr4Paths() as $_path) {
            self::fixAndReport($_path, $olds, $news);
        }
    }

    private static function fixAndReport($path, $olds, $news)
    {
        $lineNumbers = self::fixRefs($path, $olds, $news);

        foreach ($lineNumbers as $line) {
            $onFix = self::$onFix;
            $onFix($path, $line);
        }
    }

    private static function fixRefs($_path, $olds, $news)
    {
        [$olds, $occurrences] = $olds;
        $lines = file($_path);
        $changedLineNums = [];
        foreach ($lines as $lineIndex => $lineContent) {
            if (self::str_contains(\str_replace(' ', '', $lineContent), $occurrences)) {
                $response = Event::dispatch('laravel_microscope.replacing_namespace', [$_path, $lineIndex + 1, $lineContent], true);
                if ($response !== false) {
                    $count = 0;
                    $lines[$lineIndex] = \str_replace($olds, $news, $lineContent, $count);
                    $count && $changedLineNums[] = $lineIndex + 1;
                }
            }
        }

        // saves the file into disk.
        $changedLineNums && Filesystem::$fileSystem::file_put_contents($_path, \implode('', $lines));

        return $changedLineNums;
    }

    private static function str_contains($haystack, $needles)
    {
        foreach ($needles as $needle) {
            if (mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function possibleOccurrence($olds)
    {
        $keywords = ['(', '::', ';', '|', ')', "\r\n", "\n", "\r", '$', '?', ',', '&'];

        $occurrences = [];
        foreach ($olds as $old) {
            foreach ($keywords as $keyword) {
                $occurrences[] = $old.$keyword;
            }
        }

        return $occurrences;
    }
}
