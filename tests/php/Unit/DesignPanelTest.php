<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\designpanel\Models\Schema;

class DesignPanelTest extends TestCase
{
    public function testOnlyDeclaredNonSecretControlsAreAccepted(): void
    {
        $fields = Schema::fields(['group' => ['type' => 'fieldset', 'legend' => 'Design', 'fields' => [
            'title' => ['type' => 'text'], 'customcss' => ['type' => 'textarea'], 'token' => ['type' => 'text', 'security' => true],
        ]]]);
        self::assertSame(['title'], array_column($fields, 'key'));
        self::assertSame(['title' => 'Hello'], Schema::validate(['title' => 'Hello', 'customcss' => 'body{}', 'token' => 'secret'], $fields));
    }

    public function testCssCannotEscapeAColorSetting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Schema::validate(['accentColor' => '</style><script>alert(1)</script>'], [['key' => 'accentColor', 'type' => 'text']]);
    }

    public function testNumberLimitsAndChoicesAreValidatedBeforePreviewOrSave(): void
    {
        $fields = [['key' => 'typeScale', 'type' => 'number', 'min' => 85, 'max' => 125],
            ['key' => 'heroImageStyle', 'type' => 'select', 'options' => ['auto' => 'Auto', 'crest' => 'Crest']]];
        self::assertSame(['typeScale' => 110, 'heroImageStyle' => 'crest'], Schema::validate(['typeScale' => 110, 'heroImageStyle' => 'crest'], $fields));
        $this->expectException(\InvalidArgumentException::class);
        Schema::validate(['typeScale' => 1000], $fields);
    }
}
