<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Ui;

use B24\Center\Infrastructure\Http\Response;
use RuntimeException;

final class HaulPlacementPageRenderer
{
    private string $indexPath;

    public function __construct(string $projectRoot)
    {
        $this->indexPath = rtrim($projectRoot, '/');
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $request
     */
    public function render(array $payload, array $query, array $post, array $request): Response
    {
        $filePath = $this->indexPath . '/public/hauls/index.html';

        if (!is_file($filePath)) {
            throw new RuntimeException(sprintf('Hauls index file not found at "%s".', $filePath));
        }

        $html = file_get_contents($filePath);
        if ($html === false) {
            throw new RuntimeException(sprintf('Failed to read hauls index at "%s".', $filePath));
        }

        $bootstrapData = [
            'payload' => $payload,
            'get' => $query,
            'post' => $post,
            'request' => $request,
        ];

        $jsonOptions = JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_HEX_AMP;

        $bootstrapScript = '<script>window.B24_INSTALL_PAYLOAD = '
            . json_encode($bootstrapData, $jsonOptions)
            . ';</script>';

        $moduleTag = '<script src="../assets/hauls.js" type="module"></script>';

        if (str_contains($html, $moduleTag)) {
            $html = str_replace($moduleTag, $bootstrapScript . "\n    " . $moduleTag, $html);
        } else {
            $html .= "\n" . $bootstrapScript;
        }

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
