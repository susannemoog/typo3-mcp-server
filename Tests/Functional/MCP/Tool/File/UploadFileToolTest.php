<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\File;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional test for UploadFileTool URL downloads.
 *
 * Verifies that HTTP(S) URLs are downloaded correctly and that the
 * CURLOPT_RESOLVE option (removed in the stream-handler fix) does not
 * interfere with the Guzzle handler selection.
 */
class UploadFileToolTest extends AbstractFunctionalTest
{
    /**
     * A documentation-only IP (TEST-NET-3, 203.0.113.0/24) that is public
     * enough to pass assertPublicHttpUrl() and safe enough to never exist.
     * The mock catches the request before it reaches the network.
     */
    private const TEST_IMAGE_URL = 'http://203.0.113.50/team-photo.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        // fileadmin storage: uploads write actual files
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin');
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);

        $this->mockHttpDownload();
    }

    /**
     * Intercept the test download URL and return a minimal JPEG so the
     * mime-type / extension checks in UploadFileService pass.
     */
    private function mockHttpDownload(): void
    {
        // Minimal 2x2 grey JPEG (base64)
        $jpegBase64 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAACAAIDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYI4Q/SFhSRFJiMkR1CVRWJjRzgjGDkqI0Y0NEhJLDE5Oi01NjdBUmlSRUdDUkZUWFcoWGBiZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2dri4+Tl5ufo6erx8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD/AP/Z';

        $mockedResponses = [
            self::TEST_IMAGE_URL => new Response(
                200,
                ['Content-Type' => 'image/jpeg'],
                base64_decode($jpegBase64)
            ),
        ];

        // Register a Guzzle handler that intercepts the test URL but lets
        // everything else (if anything) pass through to the real handler.
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = [
            'upload_test_mock' => static function (callable $handler) use ($mockedResponses) {
                return static function ($request, array $options) use ($handler, $mockedResponses) {
                    $url = (string)$request->getUri();
                    foreach ($mockedResponses as $prefix => $response) {
                        if (str_starts_with($url, $prefix)) {
                            return new FulfilledPromise($response);
                        }
                    }
                    return $handler($request, $options);
                };
            },
        ];
    }

    /**
     * Verify that the mock is reachable before running any tool tests.
     * If this fails, the test setup is broken (wrong Guzzle config).
     */
    #[Test]
    public function mockIsReachable(): void
    {
        $response = GeneralUtility::makeInstance(RequestFactory::class)
            ->request(self::TEST_IMAGE_URL);
        self::assertEquals(200, $response->getStatusCode());
    }

    /**
     * UploadFile with a URL should download the file and create a sys_file
     * record. This test verifies that no CURLOPT_RESOLVE / stream-handler
     * conflict occurs.
     */
    #[Test]
    public function uploadFileFromUrlCreatesFileRecord(): void
    {
        $tool = GeneralUtility::makeInstance(UploadFileTool::class);
        $result = $tool->execute([
            'url' => self::TEST_IMAGE_URL,
            'fileName' => 'team-photo.jpg',
            'targetFolder' => '/',
        ]);

        self::assertFalse($result->isError, 'UploadFile returned an error: ' . ($result->content[0]->text ?? '?'));

        $data = json_decode($result->content[0]->text ?? '{}', true);
        self::assertNotNull($data['uid'] ?? null, 'No file UID in the result');
        self::assertEquals('team-photo.jpg', $data['fileName'] ?? '');
        self::assertStringContainsString('image/jpeg', $data['mimeType'] ?? '');

        // The file must exist on disk
        $fileRow = $this->fetchFile((int)$data['uid']);
        self::assertNotEmpty($fileRow, 'The sys_file record was not created');
        self::assertFileExists(
            Environment::getPublicPath() . '/fileadmin' . $fileRow['identifier'],
            'The downloaded file does not exist on disk'
        );
    }

    /**
     * UploadFile with a URL should still reject private IP addresses.
     * The assertPublicHttpUrl() check in downloadFile() must remain active
     * after the CURLOPT_RESOLVE removal.
     */
    #[Test]
    public function uploadFileRejectsPrivateIp(): void
    {
        $tool = GeneralUtility::makeInstance(UploadFileTool::class);
        $result = $tool->execute([
            'url' => 'http://127.0.0.1/config.php',
            'fileName' => 'config.php',
            'targetFolder' => '/',
        ]);

        self::assertTrue($result->isError, 'UploadFile should have rejected a private IP address');
        self::assertStringContainsString(
            'private or reserved',
            $result->content[0]->text ?? '',
            'The error message should mention the private network rejection'
        );
    }

    /**
     * UploadFile should not accept non-HTTP(S) URLs (e.g. file://).
     */
    #[Test]
    public function uploadFileRejectsNonHttpScheme(): void
    {
        $tool = GeneralUtility::makeInstance(UploadFileTool::class);
        $result = $tool->execute([
            'url' => 'file:///etc/passwd',
            'fileName' => 'passwd',
            'targetFolder' => '/',
        ]);

        self::assertTrue($result->isError, 'UploadFile should reject non-HTTP(S) URLs');
    }

    private function fetchFile(int $uid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        return $qb->select('*')
            ->from('sys_file')
            ->where($qb->expr()->eq('uid', $uid))
            ->executeQuery()
            ->fetchAssociative() ?: [];
    }
}
