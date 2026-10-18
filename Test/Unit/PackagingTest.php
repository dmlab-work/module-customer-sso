<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit;

use PHPUnit\Framework\TestCase;

class PackagingTest extends TestCase
{
    private const string LICENSE_LINE =
        ' * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.';

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param string[] $extensions
     * @return string[]
     */
    private function sourceFiles(array $extensions): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $relative = substr($path, strlen($this->root()));
            if (preg_match('#/(vendor|\.git|\.phpunit\.cache)/#', $relative)) {
                continue;
            }
            if (in_array($file->getExtension(), $extensions, true)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @return array<string, true>
     */
    private function translations(): array
    {
        $handle = fopen($this->root() . '/i18n/en_US.csv', 'r');
        self::assertNotFalse($handle);
        $phrases = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $phrases[$row[0]] = true;
        }
        fclose($handle);

        return $phrases;
    }

    /**
     * Mirrors Magento's config converter: whitespace-only text nodes are skipped, the rest kept raw.
     */
    private function configText(\DOMElement $element): string
    {
        $text = '';
        foreach ($element->childNodes as $child) {
            if ($child->nodeType === XML_CDATA_SECTION_NODE || trim((string)$child->nodeValue, "\n ") !== '') {
                $text .= $child->nodeValue;
            }
        }

        return $text;
    }

    public function testEverySourceFileCarriesTheOslNotice(): void
    {
        $files = $this->sourceFiles(['php', 'xml', 'phtml', 'js', 'html']);
        self::assertNotEmpty($files);
        foreach ($files as $path) {
            self::assertStringContainsString(self::LICENSE_LINE, (string)file_get_contents($path), $path);
        }
    }

    public function testComposerAndModuleAreVersioned(): void
    {
        $composer = json_decode((string)file_get_contents($this->root() . '/composer.json'), true);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $composer['version'] ?? '');

        $xml = simplexml_load_file($this->root() . '/etc/module.xml');
        self::assertSame('0.2.0', (string)$xml->module['setup_version']);
    }

    public function testGitattributesExportIgnoresDevelopmentFiles(): void
    {
        $rules = (string)file_get_contents($this->root() . '/.gitattributes');
        foreach (['/Test/', 'phpunit.xml*', '/.gitignore', '/.gitattributes'] as $path) {
            self::assertMatchesRegularExpression(
                '#^' . preg_quote($path, '#') . '\s+export-ignore$#m',
                $rules,
                $path
            );
        }
    }

    public function testEveryLiteralPhraseIsTranslated(): void
    {
        $phrases = $this->translations();
        $checked = 0;
        foreach ($this->sourceFiles(['php', 'phtml']) as $path) {
            if (str_contains($path, '/Test/')) {
                continue;
            }
            $literal = "'(?:[^'\\\\]|\\\\.)*'";
            preg_match_all(
                "#__\(\s*({$literal}(?:\s*\.\s*{$literal})*)\s*[,)]#",
                (string)file_get_contents($path),
                $matches
            );
            foreach ($matches[1] as $concatenation) {
                // Join 'a' . 'b' split literals into the phrase Magento's collector sees.
                preg_match_all("#'((?:[^'\\\\]|\\\\.)*)'#", $concatenation, $parts);
                self::assertArrayHasKey(stripslashes(implode('', $parts[1])), $phrases, $path);
                $checked++;
            }
        }
        self::assertGreaterThan(0, $checked);
    }

    public function testEverySystemConfigPhraseIsTranslated(): void
    {
        $phrases = $this->translations();
        $dom = new \DOMDocument();
        self::assertTrue($dom->load($this->root() . '/etc/adminhtml/system.xml'));
        foreach ((new \DOMXPath($dom))->query('//*[@translate]') as $node) {
            foreach (explode(' ', $node->getAttribute('translate')) as $child) {
                foreach ($node->getElementsByTagName($child) as $element) {
                    if ($element->parentNode === $node) {
                        self::assertArrayHasKey($this->configText($element), $phrases);
                    }
                }
            }
        }
    }

    public function testEveryLayoutTranslatableArgumentIsTranslated(): void
    {
        $phrases = $this->translations();
        foreach (glob($this->root() . '/view/frontend/layout/*.xml') as $path) {
            $xml = simplexml_load_file($path);
            foreach ($xml->xpath('//argument[@translate="true"]') as $argument) {
                self::assertArrayHasKey(trim((string)$argument), $phrases, $path);
            }
        }
    }
}
