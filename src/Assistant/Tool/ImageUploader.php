<?php

namespace Perk11\Viktor89\Assistant\Tool;

class ImageUploader
{
    public function __construct(
        private readonly string $scpTarget,
        private readonly string $publicUrlPrefix,
        private readonly string $privateKeyPath,
        private readonly ?string $publicKeyPath = null,
        private readonly ?string $keyPassphrase = null,
        private readonly int $port = 22,
    ) {
    }

    public function uploadPng(string $pngBytes): UploadedGeneratedImage
    {
        $temporaryDirectory = sys_get_temp_dir() . '/viktor89-generated-images';
        if (!is_dir($temporaryDirectory) && !mkdir($temporaryDirectory, 0777, true) && !is_dir($temporaryDirectory)) {
            throw new \RuntimeException('Failed to create temporary directory for generated images');
        }

        $fileName = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.png';
        $temporaryPath = $temporaryDirectory . '/' . $fileName;

        if (file_put_contents($temporaryPath, $pngBytes) === false) {
            throw new \RuntimeException('Failed to write generated image to a temporary file');
        }

        try {
            $this->uploadFileViaScp($temporaryPath, $fileName);
        } finally {
            unlink($temporaryPath);
        }

        return new UploadedGeneratedImage(
            rtrim($this->publicUrlPrefix, '/') . '/' . rawurlencode($fileName),
        );
    }

    private function uploadFileViaScp(string $temporaryPath, string $fileName): void
    {
        $target = $this->parseScpTarget();
        $connection = ssh2_connect($target['host'], $this->port);
        if ($connection === false) {
            throw new \RuntimeException('Failed to establish SSH connection for generated image upload');
        }

        $publicKeyPath = $this->publicKeyPath ?? ($this->privateKeyPath . '.pub');
        if (!ssh2_auth_pubkey_file(
            $connection,
            $target['username'],
            $publicKeyPath,
            $this->privateKeyPath,
            $this->keyPassphrase,
        )) {
            throw new \RuntimeException('Failed to authenticate SSH connection for generated image upload');
        }

        $sftpSessionResource = ssh2_sftp($connection);
        if ($sftpSessionResource === false) {
            throw new \RuntimeException('Failed to initialize SFTP subsystem');
        }

        $remotePath = rtrim($target['remoteDirectory'], '/') . '/' . $fileName;
        $sftpStreamUrl = 'ssh2.sftp://' . (int)$sftpSessionResource . $remotePath;

        $remoteFileStreamResource = @fopen($sftpStreamUrl, 'w');
        if ($remoteFileStreamResource === false) {
            throw new \RuntimeException('Failed to open remote file stream via SFTP');
        }

        $localTemporaryFileContents = file_get_contents($temporaryPath);
        if ($localTemporaryFileContents === false) {
            throw new \RuntimeException('Failed to read local temporary file contents');
        }

        if (fwrite($remoteFileStreamResource, $localTemporaryFileContents) === false) {
            throw new \RuntimeException('Failed to write contents to remote SFTP stream');
        }

        fclose($remoteFileStreamResource);
    }

    /**
     * @return array{username: string, host: string, remoteDirectory: string}
     */
    private function parseScpTarget(): array
    {
        if (!preg_match('/^(?P<username>[^@]+)@(?P<host>[^:]+):(?P<remoteDirectory>.+)$/', $this->scpTarget, $matches)) {
            throw new \RuntimeException('generatedImageMarkdownUploader.scpTarget must be in the format user@host:/remote/path');
        }

        return [
            'username' => $matches['username'],
            'host' => $matches['host'],
            'remoteDirectory' => $matches['remoteDirectory'],
        ];
    }
}
