<?php

declare(strict_types=1);

/**
 * @return array{id: string, json: string}
 */
function nativeCrashReport(string $id = '7f1c4a0e-5b8e-4c1a-9a51-8d3c2b1a0f00'): array
{
    return ['id' => $id, 'json' => json_encode([
        'event_id' => $id,
        'timestamp' => '2026-10-24T21:13:08Z',
        'level' => 'fatal',
        'release' => '3.0.9',
        'exception' => [
            'type' => 'NSInternalInconsistencyException',
            'value' => 'Invalid update: invalid number of rows in section 0.',
            'frames' => [
                ['file' => 'CoreFoundation', 'line' => 0, 'function' => '__exceptionPreprocess + 164', 'in_app' => false],
                ['file' => 'NativePHP', 'line' => 0, 'function' => 'NotesListController.reload() + 88', 'in_app' => true],
            ],
        ],
        'context' => ['crash' => ['mechanism' => 'ios.nsexception', 'handled' => false]],
    ], JSON_THROW_ON_ERROR)];
}
