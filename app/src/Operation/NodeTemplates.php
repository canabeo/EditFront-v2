<?php

declare(strict_types=1);

namespace EditFront\Operation;

/**
 * Built-in insert templates (C1 minimal set). The client keeps a visual
 * mirror for instant preview; the server-rendered version is canonical —
 * the preview iframe reloads after save.
 */
final class NodeTemplates
{
    private const IMG_PLACEHOLDER = 'data:image/svg+xml;charset=utf-8,'
        . '%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22320%22%20height%3D%22180%22%3E'
        . '%3Crect%20width%3D%22100%25%22%20height%3D%22100%25%22%20fill%3D%22%23e5e7eb%22%2F%3E'
        . '%3C%2Fsvg%3E';

    public const TEMPLATES = [
        'paragraph' => '<p>Новый абзац — двойной клик, чтобы изменить текст.</p>',
        'heading' => '<h2>Новый заголовок</h2>',
        'button' => '<a href="#">Кнопка</a>',
        'image' => '<img src="' . self::IMG_PLACEHOLDER . '" alt="Изображение" width="320" height="180">',
        'divider' => '<hr>',
        'section' => '<section><h2>Раздел</h2><p>Текст раздела…</p></section>',
    ];

    public function html(string $key): ?string
    {
        return self::TEMPLATES[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }
}
