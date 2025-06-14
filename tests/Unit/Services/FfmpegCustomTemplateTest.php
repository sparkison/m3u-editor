<?php

namespace Tests\Unit\Services;

use App\Services\HlsStreamService;
use App\Services\ProxyService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase; // If your tests interact with DB for settings
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class FfmpegCustomTemplateTest extends TestCase
{
    protected HlsStreamService $hlsStreamService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hlsStreamService = new HlsStreamService();

        // Mock Storage and File facades used in buildCmd
        Storage::fake('app'); // Uses a fake disk for 'app'
        File::shouldReceive('ensureDirectoryExists')->andReturn(true);
        File::shouldReceive('deleteDirectory')->andReturn(true); // For stopStream if called

        // Mock config values that buildCmd might use directly or indirectly via ProxyService
        // if not already handled by mockGeneralSettings or if defaults are fine.
        // Example: config(['proxy.ffmpeg_path' => 'test_ffmpeg_path_from_config']);
        // For these tests, we'll mostly rely on ProxyService::getStreamSettings mock
        // to control the inputs to buildCmd's template logic.
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockGeneralSettings(array $customTemplates): void
    {
        // Mock the GeneralSettings class
        // Ensure the Spatie settings container can resolve our mock
        $this->instance(
            GeneralSettings::class,
            Mockery::mock(GeneralSettings::class, function (MockInterface $mock) use ($customTemplates) {
                // Mock any other properties that getStreamSettings might access, with default values
                $mock->shouldReceive('toArray')->andReturn([]); // Default for toArray if called by Spatie
                $mock->ffmpeg_custom_command_templates = $customTemplates;
                $mock->navigation_position = 'left';
                $mock->show_breadcrumbs = true;
                $mock->show_logs = false;
                $mock->show_api_docs = false;
                $mock->show_queue_manager = false;
                $mock->content_width = 'xl';
                $mock->ffmpeg_user_agent = 'TestAgent/1.0';
                $mock->ffmpeg_debug = false;
                $mock->ffmpeg_max_tries = 3;
                $mock->ffmpeg_codec_video = 'copy';
                $mock->ffmpeg_codec_audio = 'copy';
                $mock->ffmpeg_codec_subtitles = 'copy';
                $mock->ffmpeg_path = 'ffmpeg';
                $mock->ffmpeg_hls_time = 4;
                $mock->ffmpeg_ffprobe_timeout = 5;
                $mock->hardware_acceleration_method = 'none';
                $mock->ffmpeg_vaapi_device = null;
                $mock->ffmpeg_vaapi_video_filter = null;
                $mock->ffmpeg_qsv_device = null;
                $mock->ffmpeg_qsv_video_filter = null;
                $mock->ffmpeg_qsv_encoder_options = null;
                $mock->ffmpeg_qsv_additional_args = null;
                // Add any other properties that GeneralSettings has and might be accessed
            })
        );
    }

    public function test_getStreamSettings_returns_null_when_no_custom_templates_exist()
    {
        $this->mockGeneralSettings([]); // No templates

        $settings = ProxyService::getStreamSettings();

        $this->assertArrayHasKey('ffmpeg_custom_command_template', $settings);
        $this->assertNull($settings['ffmpeg_custom_command_template']);
    }

    public function test_getStreamSettings_returns_null_when_no_template_is_enabled()
    {
        $templates = [
            ['name' => 'Template 1', 'template' => 'command1 {INPUT_URL}', 'is_enabled' => false],
            ['name' => 'Template 2', 'template' => 'command2 {INPUT_URL}', 'is_enabled' => false],
        ];
        $this->mockGeneralSettings($templates);

        $settings = ProxyService::getStreamSettings();

        $this->assertNull($settings['ffmpeg_custom_command_template']);
    }

    public function test_getStreamSettings_returns_enabled_template_string()
    {
        $expectedTemplateString = 'enabled_command {INPUT_URL}';
        $templates = [
            ['name' => 'Template 1', 'template' => 'command1 {INPUT_URL}', 'is_enabled' => false],
            ['name' => 'Template 2', 'template' => $expectedTemplateString, 'is_enabled' => true],
            ['name' => 'Template 3', 'template' => 'command3 {INPUT_URL}', 'is_enabled' => false],
        ];
        $this->mockGeneralSettings($templates);

        $settings = ProxyService::getStreamSettings();

        $this->assertEquals($expectedTemplateString, $settings['ffmpeg_custom_command_template']);
    }

    public function test_getStreamSettings_returns_first_enabled_template_if_multiple_are_somehow_enabled()
    {
        // This scenario should ideally not happen due to UI constraints, but testing backend robustness
        $firstEnabledTemplateString = 'first_enabled_command {INPUT_URL}';
        $templates = [
            ['name' => 'Template 1', 'template' => 'disabled_command {INPUT_URL}', 'is_enabled' => false],
            ['name' => 'Template 2', 'template' => $firstEnabledTemplateString, 'is_enabled' => true],
            ['name' => 'Template 3', 'template' => 'second_enabled_command {INPUT_URL}', 'is_enabled' => true],
        ];
        $this->mockGeneralSettings($templates);

        $settings = ProxyService::getStreamSettings();

        $this->assertEquals($firstEnabledTemplateString, $settings['ffmpeg_custom_command_template']);
    }

    public function test_getStreamSettings_handles_template_missing_is_enabled_key()
    {
        $templates = [
            ['name' => 'Template 1', 'template' => 'command1 {INPUT_URL}'], // is_enabled key missing
            ['name' => 'Template 2', 'template' => 'command2 {INPUT_URL}', 'is_enabled' => false],
        ];
        $this->mockGeneralSettings($templates);

        $settings = ProxyService::getStreamSettings();
        $this->assertNull($settings['ffmpeg_custom_command_template']);
    }

    public function test_getStreamSettings_handles_template_missing_template_key()
    {
        $templates = [
            ['name' => 'Template 1', 'is_enabled' => true], // template key missing
            ['name' => 'Template 2', 'template' => 'command2 {INPUT_URL}', 'is_enabled' => false],
        ];
        $this->mockGeneralSettings($templates);

        $settings = ProxyService::getStreamSettings();
        $this->assertNull($settings['ffmpeg_custom_command_template']);
    }

    public function test_buildCmd_uses_custom_template_when_active()
    {
        $customTemplate = "custom_ffmpeg -i {INPUT_URL} -ua {USER_AGENT} -o {M3U_PLAYLIST_PATH}";
        $templates = [
            ['name' => 'Active Template', 'template' => $customTemplate, 'is_enabled' => true]
        ];
        $this->mockGeneralSettings($templates); // This sets up GeneralSettings which ProxyService::getStreamSettings will use

        // HlsStreamService's buildCmd will internally call ProxyService::getStreamSettings()
        // which we've now primed via mockGeneralSettings.

        // Need to use reflection to call the private method buildCmd or make it public for testing
        $hlsService = app(HlsStreamService::class); // Get instance from container
        $reflection = new \ReflectionClass(HlsStreamService::class);
        $method = $reflection->getMethod('buildCmd');
        $method->setAccessible(true);

        $streamUrl = 'http://test.stream/url';
        $userAgent = 'TestUA';
        $modelId = 'test_id';
        $type = 'channel';

        $generatedCmd = $method->invokeArgs($hlsService, [
            $type, $modelId, $userAgent, $streamUrl
        ]);

        $this->assertStringContainsString('custom_ffmpeg', $generatedCmd);
        $this->assertStringContainsString("-i '{$streamUrl}'", $generatedCmd); // escapeshellarg will quote it
        $this->assertStringContainsString("-ua '{$userAgent}'", $generatedCmd); // escapeshellarg will quote it
        $this->assertStringContainsString("hls/{$modelId}/stream.m3u8", $generatedCmd); // Check placeholder replacement
    }

    public function test_buildCmd_uses_default_logic_when_no_custom_template_is_active()
    {
        $this->mockGeneralSettings([]); // No templates, so ProxyService will return null for custom template string

        $hlsService = app(HlsStreamService::class);
        $reflection = new \ReflectionClass(HlsStreamService::class);
        $method = $reflection->getMethod('buildCmd');
        $method->setAccessible(true);

        $streamUrl = 'http://test.stream/url';
        $userAgent = 'DefaultUA';
        $modelId = 'default_id';
        $type = 'channel';

        // Get the settings that would be used by buildCmd to know the default ffmpeg path
        $proxySettings = ProxyService::getStreamSettings();
        $expectedFfmpegPath = config('proxy.ffmpeg_path') ?: ($proxySettings['ffmpeg_path'] ?? 'jellyfin-ffmpeg');


        $generatedCmd = $method->invokeArgs($hlsService, [
            $type, $modelId, $userAgent, $streamUrl
        ]);

        // Check for elements of the default command
        $this->assertStringContainsString(escapeshellcmd($expectedFfmpegPath), $generatedCmd);
        $this->assertStringContainsString("-user_agent '{$userAgent}'", $generatedCmd); // Default command quotes user agent
        $this->assertStringContainsString("-i '{$streamUrl}'", $generatedCmd); // Default command quotes input URL
        $this->assertStringContainsString("-f hls", $generatedCmd);
        $this->assertStringContainsString("hls/{$modelId}/stream.m3u8", $generatedCmd);
        $this->assertDoesNotContain("custom_ffmpeg", $generatedCmd); // Ensure no custom parts
    }

    public function test_buildCmd_replaces_all_placeholders_in_custom_template()
    {
        $customTemplate = "{FFMPEG_PATH} -i {INPUT_URL} -user_agent {USER_AGENT} -referer {REFERER} {HWACCEL_INIT_ARGS} {HWACCEL_ARGS} {VIDEO_FILTER_ARGS} {OUTPUT_OPTIONS} {VIDEO_CODEC_ARGS} {AUDIO_CODEC_ARGS} {SUBTITLE_CODEC_ARGS} {ADDITIONAL_ARGS} -o {M3U_PLAYLIST_PATH} -seg {SEGMENT_PATH_TEMPLATE} -prefix {SEGMENT_LIST_ENTRY_PREFIX} -graph {GRAPH_FILE_PATH}";
        $templates = [
            ['name' => 'Full Template', 'template' => $customTemplate, 'is_enabled' => true]
        ];
        // Mock GeneralSettings to provide this template and other necessary ffmpeg settings
        $this->mockGeneralSettings($templates);
        // We might need to enhance mockGeneralSettings or pass specific values for HW accel etc.
        // for this particular test if we want to check those placeholders robustly.
        // For now, let's assume they resolve to empty strings if not set, or their defaults.

        $hlsService = app(HlsStreamService::class);
        $reflection = new \ReflectionClass(HlsStreamService::class);
        $method = $reflection->getMethod('buildCmd');
        $method->setAccessible(true);

        $streamUrl = 'http://test.stream/full';
        $userAgent = 'FullTestUA';
        $modelId = 'full_id';
        $type = 'channel';

        $generatedCmd = $method->invokeArgs($hlsService, [
            $type, $modelId, $userAgent, $streamUrl
        ]);

        // Check that placeholders are gone (replaced by something, even if empty string for some)
        $this->assertStringNotContainsString('{FFMPEG_PATH}', $generatedCmd);
        $this->assertStringNotContainsString('{INPUT_URL}', $generatedCmd);
        $this->assertStringNotContainsString('{USER_AGENT}', $generatedCmd);
        $this->assertStringNotContainsString('{REFERER}', $generatedCmd);
        $this->assertStringNotContainsString('{HWACCEL_INIT_ARGS}', $generatedCmd);
        $this->assertStringNotContainsString('{HWACCEL_ARGS}', $generatedCmd);
        $this->assertStringNotContainsString('{VIDEO_FILTER_ARGS}', $generatedCmd);
        $this->assertStringNotContainsString('{OUTPUT_OPTIONS}', $generatedCmd);
        $this->assertStringNotContainsString('{VIDEO_CODEC_ARGS}', $generatedCmd);
        $this->assertStringNotContainsString('{AUDIO_CODEC_ARGS}', $generatedCmd);
        $this->assertStringNotContainsString('{SUBTITLE_CODEC_ARGS}', $generatedCmd);
        $this->assertStringNotContainsString('{ADDITIONAL_ARGS}', $generatedCmd);
        $this->assertStringNotContainsString('{M3U_PLAYLIST_PATH}', $generatedCmd);
        $this->assertStringNotContainsString('{SEGMENT_PATH_TEMPLATE}', $generatedCmd);
        $this->assertStringNotContainsString('{SEGMENT_LIST_ENTRY_PREFIX}', $generatedCmd);
        $this->assertStringNotContainsString('{GRAPH_FILE_PATH}', $generatedCmd);

        // Check a few key replacements
        $proxySettings = ProxyService::getStreamSettings(); // Get settings as HLS service would
        $expectedFfmpegPath = config('proxy.ffmpeg_path') ?: ($proxySettings['ffmpeg_path'] ?? 'jellyfin-ffmpeg');

        $this->assertStringContainsString(escapeshellcmd($expectedFfmpegPath), $generatedCmd);
        $this->assertStringContainsString(escapeshellarg($streamUrl), $generatedCmd);
        $this->assertStringContainsString(escapeshellarg($userAgent), $generatedCmd);
        $this->assertStringContainsString(escapeshellarg(Storage::disk('app')->path("hls/{$modelId}/stream.m3u8")), $generatedCmd);
    }
}
