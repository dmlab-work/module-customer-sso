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
    private const LICENSE_LINE =
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

    public function testComposerSupportsMagento247AndPhp82(): void
    {
        $composer = json_decode((string)file_get_contents($this->root() . '/composer.json'), true);
        self::assertSame('~8.2.0||~8.3.0||~8.4.0||~8.5.0', $composer['require']['php']);
        $magento = array_filter(
            $composer['require'],
            static fn (string $name): bool => str_starts_with($name, 'magento/'),
            ARRAY_FILTER_USE_KEY
        );
        self::assertSame([
            'magento/framework' => '^103.0.7',
            'magento/module-backend' => '^102.0.7',
            'magento/module-checkout' => '^100.4.7',
            'magento/module-config' => '^101.2.7',
            'magento/module-customer' => '^103.0.7',
            'magento/module-store' => '^101.1.7',
        ], $magento);
        self::assertSame('^11.5||^12.5', $composer['require-dev']['phpunit/phpunit']);
        self::assertSame('https://github.com/dmlab-work/module-customer-sso', $composer['homepage']);
        self::assertSame($composer['homepage'] . '/issues', $composer['support']['issues']);
        self::assertArrayHasKey('dmlab/module-customer-passkey', $composer['suggest']);
    }

    public function testEveryReadmeImageExists(): void
    {
        preg_match_all('#!\[[^\]]*\]\(([^)\s]+)\)#', (string)file_get_contents($this->root() . '/README.md'), $images);
        $local = array_filter($images[1], static fn (string $src): bool => !preg_match('#^https?://#', $src));
        self::assertNotEmpty($local);
        foreach ($local as $src) {
            self::assertFileExists($this->root() . '/' . $src);
        }
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
