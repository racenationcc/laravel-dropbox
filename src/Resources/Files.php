<?php

namespace RaceNation\Dropbox\Resources;

use RaceNation\Dropbox\Dropbox;
use GuzzleHttp\Client;
use Exception;

use function PHPUnit\Framework\throwException;
use function trigger_error;

class Files extends Dropbox
{
    public function __construct(
        protected bool $useToken = false,
        protected bool $useRefreshToken = false
    )
    {
        parent::__construct();
    }

    public function listContents($path = '')
    {
        $pathRequest = $this->forceStartingSlash($path);

        return $this->post('files/list_folder', [
            'data' => [
                'path' => $path == '' ? '' : $pathRequest
            ],
            'useToken' => $this->useToken,
            'useConfigRefreshToken' => $this->useRefreshToken
        ]);
    }

    public function listContentsContinue($cursor = '')
    {
        return $this->post('files/list_folder/continue', [
            'data' => [
                'cursor' => $cursor
            ],
            'useToken' => $this->useToken,
            'useConfigRefreshToken' => $this->useRefreshToken
        ]);
    }

    public function move($fromPath, $toPath, $autoRename = false, $allowOwnershipTransfer = false)
    {
        $this->post('files/move_v2', [
            'data' => [
                "from_path" => $fromPath,
                "to_path" => $toPath,
                "autorename" => $autoRename,
                "allow_ownership_transfer" => $allowOwnershipTransfer
            ],
            'useToken' => $this->useToken,
            'useConfigRefreshToken' => $this->useRefreshToken
        ]);
    }

    public function delete($path)
    {
        $path = $this->forceStartingSlash($path);

        return $this->post('files/delete_v2', [
            'data' => [
                'path' => $path
            ],
            'useToken' => $this->useToken,
            'useConfigRefreshToken' => $this->useRefreshToken
        ]);
    }

    public function createFolder($path)
    {
        $path = $this->forceStartingSlash($path);

        return $this->post('files/create_folder', [
            'data' => [
                'path' => $path,
            ],
            'useToken' => $this->useToken,
            'useConfigRefreshToken' => $this->useRefreshToken
        ]);
    }

    public function search($query)
    {
        return $this->post('files/search', [
            'data' => [
                'path' => '',
                'query' => $query,
                'start' => 0,
                'max_results' => 1000,
                'mode' => 'filename'
            ],
            'useToken' => $this->useToken,
            'useConfigRefreshToken' => $this->useRefreshToken
        ]);
    }

    public function doesFileExist(string $path): bool
    {
        $path = $this->forceStartingSlash($path);

        try {

            $ch = curl_init('https://api.dropboxapi.com/2/files/get_metadata');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->getAccessToken($this->useRefreshToken),
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['path' => $path]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);

            $responseData = json_decode($response);

            return isset($responseData->id);

        } catch (Exception $e) {

            throw new Exception($e->getMessage());

        }

    }

    public function upload($path, $uploadPath, $mode = 'add')
    {
        if ($uploadPath == '') {
            throw new Exception('File is required');
        }

	    $path     = ($path !== '') ? $this->forceStartingSlash($path) : '';
	    $contents = $this->getContents($uploadPath);
        $filename = $this->getFilenameFromPath($uploadPath);
        $path     = $path.$filename;

        return $this->uploadCurl($path, $contents, $mode);

    }

    //upload a stream of data to custom filename
    public function uploadStream(string $path, string $fileName, string $fileContents, string $mode)
    {
        $path = ($path !== '') ? $this->forceStartingSlash($path) : '';
        //strip trailing slash from uploadLocation if they have it as we're putting there
        return $this->uploadCurl(rtrim($path, '/') . '/' . $fileName, $fileContents, $mode);

    }

    protected function uploadCurl(string $pathWithFileName, string $fileContents, string $mode)
    {
        try {

            $ch = curl_init('https://content.dropboxapi.com/2/files/upload');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->getAccessToken($this->useRefreshToken),
                'Content-Type: application/octet-stream',
                'Dropbox-API-Arg: ' .
                    json_encode([
                        "path" => $pathWithFileName,
                        "mode" => $mode,
                        "autorename" => true,
                        "mute" => false
                    ])
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContents);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);

            return $response;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function download($path, $destFolder = '')
    {
        $path = $this->forceStartingSlash($path);

        try {
            $client = new Client;

            $response = $client->post("https://content.dropboxapi.com/2/files/download", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken($this->useRefreshToken),
                    'Dropbox-API-Arg' => json_encode([
                        'path' => $path
                    ])
                ]
            ]);

            $header = json_decode($response->getHeader('Dropbox-Api-Result')[0], true);
            $body = $response->getBody()->getContents();

            if (empty($destFolder)){
                $destFolder = 'dropbox-temp';

                if (! is_dir($destFolder)) {
                    mkdir($destFolder);
                }
            }

            file_put_contents($destFolder.$header['name'], $body);

            return response()->download($destFolder.$header['name'], $header['name'])->deleteFileAfterSend();

        } catch (ClientException $e) {
            throw new Exception($e->getResponse()->getBody()->getContents());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function getContentsFile($path)
    {
        $path = $this->forceStartingSlash($path);

        try {
            $client = new Client;

            $response = $client->post("https://content.dropboxapi.com/2/files/download", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken($this->useRefreshToken),
                    'Dropbox-API-Arg' => json_encode([
                                                         'path' => $path
                                                     ])
                ]
            ]);

            return $response->getBody()->getContents();

        } catch (ClientException $e) {
            throw new Exception($e->getResponse()->getBody()->getContents());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    protected function getFilenameFromPath($filePath)
    {
        $parts = explode('/', $filePath);
        $filename = end($parts);
        return $this->forceStartingSlash($filename);
    }

    protected function getContents($filePath)
    {
        return file_get_contents($filePath);
    }

}
