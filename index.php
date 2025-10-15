<?php
// Ultimate File Management Bot v8.0 - Render.com Optimized
// Complete PHP Version with all features

// === CONFIGURATION ===
define('API_ID', 21944581);
define('API_HASH', '7b1c174a5cd3466e25a976c39a791737');
define('BOT_TOKEN', '7882727627:AAEHg7JGBoeK8_nD8G1MwL8EEl_Oo5-AB9s');
define('OWNER_ID', 1080317415);

define('VIDEO_WIDTH', 430);
define('VIDEO_HEIGHT', 241);
define('CHUNK_SIZE', 64 * 1024);
define('RETRY_COUNT', 10);

// Render.com specific settings
define('BASE_URL', getenv('RENDER_EXTERNAL_URL') ?: 'https://your-app-name.onrender.com');
define('IS_PRODUCTION', getenv('RENDER') ? true : false);

// Ensure required extensions are loaded
if (!extension_loaded('curl')) {
    die("❌ cURL extension is required but not loaded.");
}

// Create required directories
if (!file_exists('uploads')) {
    mkdir('uploads', 0777, true);
}
if (!file_exists('temp')) {
    mkdir('temp', 0777, true);
}

// === HELPER FUNCTIONS ===
function human_readable($size, $suffix = "B") {
    $units = ["", "K", "M", "G", "T"];
    foreach ($units as $unit) {
        if ($size < 1024) {
            return sprintf("%.2f%s%s", $size, $unit, $suffix);
        }
        $size /= 1024;
    }
    return sprintf("%.2fP%s", $size, $suffix);
}

function calc_checksum($file_path) {
    if (!file_exists($file_path)) {
        return ["Error", "File not found"];
    }
    $md5 = md5_file($file_path);
    $sha1 = sha1_file($file_path);
    return [$md5, $sha1];
}

function is_video_file($filename) {
    $video_extensions = ['.mp4', '.mkv', '.avi', '.mov', '.wmv', '.flv', '.webm', '.m4v', '.3gp', '.ogg', '.mpeg', '.mpg', '.ts', '.vob', '.m4v'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array('.' . $ext, $video_extensions);
}

function is_image_file($filename) {
    $image_extensions = ['.jpg', '.jpeg', '.png', '.gif', '.bmp', '.webp'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array('.' . $ext, $image_extensions);
}

function load_metadata() {
    $metadata_file = 'metadata.json';
    if (file_exists($metadata_file)) {
        $content = file_get_contents($metadata_file);
        return json_decode($content, true) ?: [];
    }
    return [];
}

function save_metadata($data) {
    $metadata_file = 'metadata.json';
    $result = file_put_contents($metadata_file, json_encode($data, JSON_PRETTY_PRINT));
    if ($result !== false) {
        chmod($metadata_file, 0666);
    }
    return $result !== false;
}

function generate_thumbnail($video_path, $output_path, $time = '00:00:05') {
    // Simple thumbnail generation using FFmpeg
    $cmd = "ffmpeg -i " . escapeshellarg($video_path) . " -ss {$time} -vframes 1 -q:v 2 " . escapeshellarg($output_path) . " 2>&1";
    exec($cmd, $output, $return_code);
    
    if ($return_code === 0 && file_exists($output_path)) {
        return true;
    }
    
    // Fallback: create a simple text-based thumbnail
    return create_text_thumbnail($output_path);
}

function create_text_thumbnail($output_path) {
    $width = 200;
    $height = 150;
    
    $image = imagecreatetruecolor($width, $height);
    $bg_color = imagecolorallocate($image, 40, 40, 40);
    $text_color = imagecolorallocate($image, 255, 255, 255);
    
    imagefill($image, 0, 0, $bg_color);
    
    $text = "Video\nThumbnail";
    $font = 3; // Built-in font
    $text_width = imagefontwidth($font) * strlen($text);
    $text_height = imagefontheight($font) * 2;
    
    $x = ($width - $text_width) / 2;
    $y = ($height - $text_height) / 2;
    
    imagestring($image, $font, $x, $y, "Video", $text_color);
    imagestring($image, $font, $x, $y + 20, "Thumbnail", $text_color);
    
    $result = imagepng($image, $output_path);
    imagedestroy($image);
    
    return $result;
}

function clean_filename($filename) {
    // Remove invalid characters from filename
    $filename = preg_replace('/[^\w\s\.\-]/', '', $filename);
    $filename = preg_replace('/\s+/', '_', $filename);
    return $filename;
}

function get_video_duration($video_path) {
    $cmd = "ffprobe -v quiet -print_format json -show_format " . escapeshellarg($video_path);
    $output = shell_exec($cmd);
    $info = json_decode($output, true);
    
    if (isset($info['format']['duration'])) {
        return intval($info['format']['duration']);
    }
    return 0;
}

function get_video_dimensions($video_path) {
    $cmd = "ffprobe -v quiet -print_format json -show_streams " . escapeshellarg($video_path);
    $output = shell_exec($cmd);
    $info = json_decode($output, true);
    
    if (isset($info['streams'][0]['width']) && isset($info['streams'][0]['height'])) {
        return [
            'width' => $info['streams'][0]['width'],
            'height' => $info['streams'][0]['height']
        ];
    }
    return ['width' => VIDEO_WIDTH, 'height' => VIDEO_HEIGHT];
}

// === TELEGRAM BOT HANDLER ===
class TelegramBot {
    private $token;
    private $api_url;
    private $state;
    
    public function __construct($token) {
        $this->token = $token;
        $this->api_url = "https://api.telegram.org/bot{$token}/";
        $this->state = $this->load_state();
    }
    
    private function load_state() {
        $state_file = 'bot_state.json';
        if (file_exists($state_file)) {
            $content = file_get_contents($state_file);
            return json_decode($content, true) ?: [OWNER_ID => ['metadata' => load_metadata()]];
        }
        return [OWNER_ID => ['metadata' => load_metadata()]];
    }
    
    private function save_state() {
        $state_file = 'bot_state.json';
        $result = file_put_contents($state_file, json_encode($this->state, JSON_PRETTY_PRINT));
        if ($result !== false) {
            chmod($state_file, 0666);
        }
        return $result !== false;
    }
    
    public function get_user_state($user_id = null) {
        $user_id = $user_id ?: OWNER_ID;
        if (!isset($this->state[$user_id])) {
            $this->state[$user_id] = ['metadata' => load_metadata()];
        }
        return $this->state[$user_id];
    }
    
    public function update_user_state($key, $value, $user_id = null) {
        $user_id = $user_id ?: OWNER_ID;
        $this->state[$user_id][$key] = $value;
        return $this->save_state();
    }
    
    public function api_request($method, $params = []) {
        $url = $this->api_url . $method;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($http_code !== 200) {
            error_log("Telegram API Error: HTTP $http_code - $error - $result");
            return false;
        }
        
        return json_decode($result, true);
    }
    
    public function send_message($chat_id, $text, $reply_markup = null, $parse_mode = 'HTML') {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => $parse_mode
        ];
        
        if ($reply_markup) {
            $params['reply_markup'] = json_encode($reply_markup);
        }
        
        for ($i = 0; $i < RETRY_COUNT; $i++) {
            $result = $this->api_request('sendMessage', $params);
            if ($result !== false) {
                return $result;
            }
            sleep(1);
        }
        return false;
    }
    
    public function edit_message($chat_id, $message_id, $text, $reply_markup = null) {
        $params = [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($reply_markup) {
            $params['reply_markup'] = json_encode($reply_markup);
        }
        
        return $this->api_request('editMessageText', $params);
    }
    
    public function send_video($chat_id, $video_path, $caption = '', $thumb_path = null, $duration = 0, $width = 0, $height = 0) {
        if (!file_exists($video_path)) {
            error_log("Video file not found: $video_path");
            return false;
        }
        
        $params = [
            'chat_id' => $chat_id,
            'video' => new CURLFile($video_path),
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'supports_streaming' => true
        ];
        
        if ($duration > 0) {
            $params['duration'] = $duration;
        }
        
        if ($width > 0 && $height > 0) {
            $params['width'] = $width;
            $params['height'] = $height;
        }
        
        if ($thumb_path && file_exists($thumb_path)) {
            $params['thumb'] = new CURLFile($thumb_path);
        }
        
        for ($i = 0; $i < RETRY_COUNT; $i++) {
            $result = $this->api_request('sendVideo', $params);
            if ($result !== false) {
                return $result;
            }
            sleep(2);
        }
        return false;
    }
    
    public function send_document($chat_id, $document_path, $caption = '', $thumb_path = null) {
        if (!file_exists($document_path)) {
            error_log("Document file not found: $document_path");
            return false;
        }
        
        $params = [
            'chat_id' => $chat_id,
            'document' => new CURLFile($document_path),
            'caption' => $caption,
            'parse_mode' => 'HTML'
        ];
        
        if ($thumb_path && file_exists($thumb_path)) {
            $params['thumb'] = new CURLFile($thumb_path);
        }
        
        for ($i = 0; $i < RETRY_COUNT; $i++) {
            $result = $this->api_request('sendDocument', $params);
            if ($result !== false) {
                return $result;
            }
            sleep(2);
        }
        return false;
    }
    
    public function download_file($file_id, $destination) {
        $file_info = $this->api_request('getFile', ['file_id' => $file_id]);
        
        if (!$file_info || !$file_info['ok']) {
            error_log("Failed to get file info for file_id: $file_id");
            return false;
        }
        
        $file_path = $file_info['result']['file_path'];
        $file_url = "https://api.telegram.org/file/bot{$this->token}/{$file_path}";
        
        $file_content = file_get_contents($file_url);
        if ($file_content === false) {
            error_log("Failed to download file from: $file_url");
            return false;
        }
        
        $result = file_put_contents($destination, $file_content);
        return $result !== false;
    }
    
    public function answer_callback_query($callback_id, $text = '', $show_alert = false) {
        $params = [
            'callback_query_id' => $callback_id,
            'text' => $text,
            'show_alert' => $show_alert
        ];
        
        return $this->api_request('answerCallbackQuery', $params);
    }
    
    public function delete_message($chat_id, $message_id) {
        $params = [
            'chat_id' => $chat_id,
            'message_id' => $message_id
        ];
        
        return $this->api_request('deleteMessage', $params);
    }
}

// === COMMAND HANDLERS ===
function handle_start_help($bot, $chat_id) {
    $text = "🤖 <b>Ultimate File Management Bot v8.0</b>\n\n"
          . "<b>Owner:</b> @MahatabAnsari786\n"
          . "<b>Host:</b> Render.com 🚀\n\n"
          . "<b>Main Commands:</b>\n"
          . "• /setname <filename.ext> - Set new filename\n"
          . "• /clearname - Clear set filename\n"
          . "• /split_on - Split mode ON\n"
          . "• /split_off - Split mode OFF\n"
          . "• /status - Show current settings\n"
          . "• /metadata - Set custom metadata\n"
          . "• /setthumb - Set custom thumbnail\n"
          . "• /view_thumb - View current thumbnail\n"
          . "• /del_thumb - Delete custom thumbnail\n\n"
          . "<b>Video Tools:</b>\n"
          . "• /video_renamer - Video renaming options\n"
          . "• /video_converter - Convert video formats\n"
          . "• /merge_videos - Start video merging\n"
          . "• /merge_start - Start merging queued videos\n"
          . "• /merge_status - Show merge status\n"
          . "• /merge_clear - Clear merge queue\n\n"
          . "<b>Auto Features:</b>\n"
          . "• Video files upload as VIDEO (not document)\n"
          . "• <b>Fixed Video Dimensions:</b> " . VIDEO_WIDTH . "×" . VIDEO_HEIGHT . " pixels\n"
          . "• File Splitting: DISABLED - All files upload as single\n"
          . "• Video merging capability\n"
          . "• Transparent text watermark thumbnail\n"
          . "• MD5 & SHA1 checksum\n"
          . "• Multiple file queue\n"
          . "• Auto-clean temp files\n"
          . "• Upload retry (3 attempts)";
    
    $bot->send_message($chat_id, $text);
}

function handle_video_renamer($bot, $chat_id) {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '🎥 VIDEO RENAMER', 'callback_data' => 'renamer_video']],
            [['text' => '📹 Rename As Video', 'callback_data' => 'rename_as_video']],
            [['text' => '📄 Rename As File', 'callback_data' => 'rename_as_file']],
            [['text' => '❌ Cancel', 'callback_data' => 'cancel_rename']]
        ]
    ];
    
    $text = "🎥 <b>Video Renamer</b>\n\n"
          . "✶ Video Renamer: Is option me rename karne ke liye words ki limit di nahi gayi hai, "
          . "isliye agar naam bahut lamba hua to error aa sakta hai. ✶\n\n"
          . "Better samajhne ke liye ek baar \"👉 TG Limits\" google pe search kar ke padho.\n\n"
          . "<b>Note:</b> Settings in options pe kaam nahi karti — \"As video\" ya \"As file\".\n\n"
          . "<b>Apna action choose karo 👇</b>";
    
    $bot->send_message($chat_id, $text, $keyboard);
}

function handle_video_converter($bot, $chat_id) {
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'MP4', 'callback_data' => 'convert_mp4'],
                ['text' => 'MKV', 'callback_data' => 'convert_mkv']
            ],
            [
                ['text' => 'AVI', 'callback_data' => 'convert_avi'],
                ['text' => 'M4V', 'callback_data' => 'convert_m4v']
            ],
            [
                ['text' => '🎥 Convert as Video', 'callback_data' => 'convert_as_video'],
                ['text' => '📄 Convert as File', 'callback_data' => 'convert_as_file']
            ],
            [['text' => '❌ Cancel', 'callback_data' => 'cancel_convert']]
        ]
    ];
    
    $text = "🎬 <b>Video Converter</b>\n\n"
          . "Agar tum video pe custom thumbnail set karna chahte ho, to pehle ek image bhejo "
          . "aur use Custom Thumbnail ke roop me save karo.\n\n"
          . "<b>Note:</b> Settings in mentioned options pe kaam nahi karti — \"As video\" ya \"As file\".\n\n"
          . "<b>Apna appropriate action choose karo 👇</b>\n\n"
          . "<b>Video Converter:</b>";
    
    $bot->send_message($chat_id, $text, $keyboard);
}

function handle_merge_videos($bot, $chat_id) {
    $user_state = $bot->get_user_state();
    $merge_queue = $user_state['merge_queue'] ?? [];
    
    $text = "🎬 <b>Video Merger</b>\n\n";
    
    if (empty($merge_queue)) {
        $text .= "❌ <b>No videos in merge queue</b>\n\n";
    } else {
        $text .= "📋 <b>Videos in queue:</b> " . count($merge_queue) . "\n\n";
        foreach ($merge_queue as $index => $video) {
            $text .= ($index + 1) . ". " . basename($video) . "\n";
        }
        $text .= "\n";
    }
    
    $text .= "📥 <b>Send video files to add to merge queue</b>\n\n"
           . "<b>Commands:</b>\n"
           . "• /merge_start - Start merging\n"
           . "• /merge_clear - Clear queue\n"
           . "• /merge_status - Show status";
    
    $bot->send_message($chat_id, $text);
}

function handle_merge_start($bot, $chat_id) {
    $user_state = $bot->get_user_state();
    $merge_queue = $user_state['merge_queue'] ?? [];
    
    if (empty($merge_queue)) {
        $bot->send_message($chat_id, "❌ <b>Merge queue is empty!</b>\n\nSend some videos first using /merge_videos");
        return;
    }
    
    if (count($merge_queue) < 2) {
        $bot->send_message($chat_id, "❌ <b>Need at least 2 videos to merge!</b>\n\nCurrently have: " . count($merge_queue) . " video(s)");
        return;
    }
    
    $bot->send_message($chat_id, "🔄 <b>Starting video merge...</b>\n\n📊 <b>Videos to merge:</b> " . count($merge_queue) . "\n⏳ <b>This may take a while...</b>");
    
    // Simple merge implementation
    $output_file = 'temp/merged_' . time() . '.mp4';
    $file_list = 'temp/file_list_' . time() . '.txt';
    
    // Create file list for FFmpeg
    $list_content = '';
    foreach ($merge_queue as $video) {
        if (file_exists($video)) {
            $list_content .= "file '" . realpath($video) . "'\n";
        }
    }
    
    file_put_contents($file_list, $list_content);
    
    // Merge using FFmpeg
    $cmd = "ffmpeg -f concat -safe 0 -i " . escapeshellarg($file_list) . " -c copy " . escapeshellarg($output_file) . " 2>&1";
    exec($cmd, $output, $return_code);
    
    if ($return_code === 0 && file_exists($output_file)) {
        $file_size = human_readable(filesize($output_file));
        list($md5, $sha1) = calc_checksum($output_file);
        
        $caption = "✅ <b>Videos Merged Successfully!</b>\n\n"
                 . "📊 <b>Total Videos:</b> " . count($merge_queue) . "\n"
                 . "💾 <b>Output Size:</b> $file_size\n"
                 . "🔒 <b>MD5:</b> <code>$md5</code>\n"
                 . "🔒 <b>SHA1:</b> <code>$sha1</code>";
        
        // Send merged video
        $result = $bot->send_video($chat_id, $output_file, $caption, null, 0, VIDEO_WIDTH, VIDEO_HEIGHT);
        
        if ($result) {
            $bot->send_message($chat_id, "🎉 <b>Merge completed successfully!</b>");
            // Clear merge queue after successful merge
            $bot->update_user_state('merge_queue', []);
        } else {
            $bot->send_message($chat_id, "❌ <b>Failed to send merged video!</b>");
        }
        
        // Cleanup
        unlink($output_file);
    } else {
        $bot->send_message($chat_id, "❌ <b>Merge failed!</b>\n\nError: " . implode("\n", array_slice($output, 0, 5)));
    }
    
    // Cleanup file list
    if (file_exists($file_list)) {
        unlink($file_list);
    }
}

function handle_merge_status($bot, $chat_id) {
    $user_state = $bot->get_user_state();
    $merge_queue = $user_state['merge_queue'] ?? [];
    
    $text = "📊 <b>Merge Status</b>\n\n";
    
    if (empty($merge_queue)) {
        $text .= "❌ <b>No videos in merge queue</b>\n\n";
    } else {
        $text .= "✅ <b>Videos in queue:</b> " . count($merge_queue) . "\n\n";
        foreach ($merge_queue as $index => $video) {
            $file_size = file_exists($video) ? human_readable(filesize($video)) : 'Missing';
            $text .= ($index + 1) . ". " . basename($video) . " ($file_size)\n";
        }
    }
    
    $text .= "\n<b>Commands:</b>\n"
           . "• Send more videos to add to queue\n"
           . "• /merge_start - Start merging\n"
           . "• /merge_clear - Clear queue";
    
    $bot->send_message($chat_id, $text);
}

function handle_merge_clear($bot, $chat_id) {
    $bot->update_user_state('merge_queue', []);
    $bot->send_message($chat_id, "✅ <b>Merge queue cleared!</b>");
}

function handle_set_name($bot, $chat_id, $text) {
    $args = explode(' ', $text, 2);
    if (count($args) < 2) {
        $bot->send_message($chat_id, "❌ <b>Usage:</b> <code>/setname &lt;filename.ext&gt;</code>\n\nExample: <code>/setname Movie (2024) 1080p.mkv</code>");
        return;
    }
    
    $new_name = clean_filename(trim($args[1]));
    $bot->update_user_state('new_name', $new_name);
    $bot->send_message($chat_id, "✅ Name set: <code>" . htmlspecialchars($new_name) . "</code>");
}

function handle_clear_name($bot, $chat_id) {
    $bot->update_user_state('new_name', null);
    $bot->send_message($chat_id, "✅ Name cleared.");
}

function handle_split_on($bot, $chat_id) {
    $bot->update_user_state('split_enabled', true);
    $bot->send_message($chat_id, "✅ <b>4GB Split Mode: ON</b>\n\nLarge files will be split into 4GB chunks.");
}

function handle_split_off($bot, $chat_id) {
    $bot->update_user_state('split_enabled', false);
    $bot->send_message($chat_id, "✅ <b>4GB Split Mode: OFF</b>\n\nAll files will be uploaded as single files.");
}

function handle_set_thumb($bot, $chat_id) {
    $bot->send_message($chat_id, "🖼️ <b>Send an image to set as custom thumbnail</b>\n\nSupported formats: JPG, PNG, GIF, WEBP");
    $bot->update_user_state('awaiting_thumb', true);
}

function handle_view_thumb($bot, $chat_id) {
    if (file_exists('custom_thumb.jpg')) {
        $bot->send_message($chat_id, "🖼️ <b>Current custom thumbnail:</b>");
        $bot->send_document($chat_id, 'custom_thumb.jpg', "Custom Thumbnail");
    } else {
        $bot->send_message($chat_id, "❌ <b>No custom thumbnail set!</b>\n\nUse /setthumb to set one.");
    }
}

function handle_del_thumb($bot, $chat_id) {
    if (file_exists('custom_thumb.jpg')) {
        unlink('custom_thumb.jpg');
        $bot->send_message($chat_id, "✅ <b>Custom thumbnail deleted!</b>");
    } else {
        $bot->send_message($chat_id, "❌ <b>No custom thumbnail found!</b>");
    }
}

function handle_status($bot, $chat_id) {
    $user_state = $bot->get_user_state();
    $name = $user_state['new_name'] ?? '❌ Not set';
    $split = isset($user_state['split_enabled']) && $user_state['split_enabled'] ? '✅ ON' : '❌ OFF';
    $thumb_status = file_exists('thumb.png') ? '✅ EXISTS' : '❌ NOT FOUND';
    $custom_thumb = file_exists('custom_thumb.jpg') ? '✅ SET' : '❌ NOT SET';
    
    $metadata = $user_state['metadata'] ?? [];
    $title = $metadata['title'] ?? 'Not set';
    $artist = $metadata['artist'] ?? 'Not set';
    $year = $metadata['year'] ?? 'Not set';
    
    $merge_queue = $user_state['merge_queue'] ?? [];
    $merge_status = count($merge_queue) > 0 ? '📊 ' . count($merge_queue) . ' videos' : '❌ Not active';
    
    $rename_mode = $user_state['rename_mode'] ?? 'Not set';
    $convert_mode = $user_state['convert_mode'] ?? 'Not set';
    $convert_format = $user_state['convert_format'] ?? 'Not set';
    
    $text = "🤖 <b>Bot Status - Render.com</b>\n\n"
          . "• <b>Owner:</b> @MahatabAnsari786\n"
          . "• <b>Filename:</b> <code>$name</code>\n"
          . "• <b>4GB Split:</b> $split\n"
          . "• <b>Video Dimensions:</b> " . VIDEO_WIDTH . "×" . VIDEO_HEIGHT . "\n"
          . "• <b>Default Thumb:</b> $thumb_status\n"
          . "• <b>Custom Thumb:</b> $custom_thumb\n"
          . "• <b>Merge Queue:</b> $merge_status\n"
          . "• <b>Rename Mode:</b> $rename_mode\n"
          . "• <b>Convert Mode:</b> $convert_mode\n"
          . "• <b>Convert Format:</b> $convert_format\n"
          . "• <b>Environment:</b> " . (IS_PRODUCTION ? "Production 🚀" : "Development") . "\n\n"
          . "📝 <b>Metadata:</b>\n"
          . "• Title: <code>$title</code>\n"
          . "• Artist: <code>$artist</code>\n"
          . "• Year: <code>$year</code>\n\n"
          . "<b>Send any file to start processing!</b>";
    
    $bot->send_message($chat_id, $text);
}

function handle_metadata($bot, $chat_id, $text) {
    $args = explode(' ', $text, 2);
    
    if (count($args) < 2) {
        $user_state = $bot->get_user_state();
        $metadata = $user_state['metadata'] ?? [];
        
        $current_metadata = "<b>📝 Current Metadata:</b>\n";
        if (!empty($metadata)) {
            foreach ($metadata as $key => $value) {
                $current_metadata .= "• <b>" . ucfirst($key) . ":</b> <code>$value</code>\n";
            }
        } else {
            $current_metadata .= "❌ No metadata set\n";
        }
        
        $help_text = $current_metadata . "\n"
                   . "<b>📋 Set Metadata:</b>\n\n"
                   . "<b>Usage:</b> <code>/metadata key=value</code>\n\n"
                   . "<b>Available keys:</b>\n"
                   . "• <code>title=Your Title</code>\n"
                   . "• <code>artist=Artist Name</code>\n"
                   . "• <code>author=Author Name</code>\n"
                   . "• <code>video=Video Info</code>\n"
                   . "• <code>audio=Audio Info</code>\n"
                   . "• <code>subtitle=Subtitle Info</code>\n"
                   . "• <code>year=2024</code>\n"
                   . "• <code>genre=Action</code>\n"
                   . "• <code>quality=1080p</code>\n\n"
                   . "<b>Examples:</b>\n"
                   . "• <code>/metadata title=Pushpa2 artist=Allu Arjun</code>\n"
                   . "• <code>/metadata video=Encoded By Silent Teams audio=DD+5.1</code>\n"
                   . "• <code>/metadata title=Movie Name quality=1080p year=2024</code>\n\n"
                   . "<b>Multiple values:</b> <code>/metadata title=ABC artist=XYZ quality=1080p</code>";
        
        $bot->send_message($chat_id, $help_text);
        return;
    }
    
    $user_state = $bot->get_user_state();
    if (!isset($user_state['metadata'])) {
        $user_state['metadata'] = [];
    }
    
    $pairs = explode(' ', $args[1]);
    $changes = [];
    
    foreach ($pairs as $pair) {
        if (strpos($pair, '=') !== false) {
            list($key, $value) = explode('=', $pair, 2);
            $key = trim(strtolower($key));
            $user_state['metadata'][$key] = trim($value);
            $changes[] = "• <b>" . ucfirst($key) . ":</b> <code>$value</code>";
        }
    }
    
    $bot->update_user_state('metadata', $user_state['metadata']);
    save_metadata($user_state['metadata']);
    
    if (!empty($changes)) {
        $updated_text = "✅ <b>Metadata Updated Successfully!</b>\n\n" . implode("\n", $changes);
        $updated_text .= "\n\n<b>Total keys set:</b> " . count($user_state['metadata']);
        $bot->send_message($chat_id, $updated_text);
    } else {
        $bot->send_message($chat_id, "❌ No valid key=value pairs found!\n\nExample: <code>/metadata title=Movie Name quality=1080p</code>");
    }
}

function handle_callback_query($bot, $callback_query) {
    $data = $callback_query['data'];
    $message = $callback_query['message'];
    $chat_id = $message['chat']['id'];
    $message_id = $message['message_id'];
    $callback_id = $callback_query['id'];
    
    $user_state = $bot->get_user_state();
    
    if ($data === 'cancel_rename' || $data === 'cancel_convert') {
        $bot->edit_message($chat_id, $message_id, "❌ <b>Operation cancelled!</b>");
        $bot->answer_callback_query($callback_id, "Operation cancelled");
        return;
    }
    
    if (strpos($data, 'renamer_') === 0) {
        switch ($data) {
            case 'renamer_video':
                $bot->update_user_state('rename_mode', 'video_renamer');
                $text = "✅ <b>VIDEO RENAMER Mode Activated!</b>\n\n"
                      . "Now send me any video file and I'll rename it with your set filename.\n\n"
                      . "<b>Note:</b> Use <code>/setname &lt;filename.ext&gt;</code> to set the new filename first!";
                break;
                
            case 'rename_as_video':
                $bot->update_user_state('rename_mode', 'as_video');
                $text = "✅ <b>Rename As Video Mode Activated!</b>\n\n"
                      . "Now send me any file and I'll rename and upload it as VIDEO.\n\n"
                      . "<b>Note:</b> Use <code>/setname &lt;filename.ext&gt;</code> to set the new filename first!";
                break;
                
            case 'rename_as_file':
                $bot->update_user_state('rename_mode', 'as_file');
                $text = "✅ <b>Rename As File Mode Activated!</b>\n\n"
                      . "Now send me any file and I'll rename and upload it as DOCUMENT.\n\n"
                      . "<b>Note:</b> Use <code>/setname &lt;filename.ext&gt;</code> to set the new filename first!";
                break;
        }
        
        $bot->edit_message($chat_id, $message_id, $text);
        $bot->answer_callback_query($callback_id, "Mode activated");
    }
    elseif (strpos($data, 'convert_') === 0) {
        $format_map = [
            'convert_mp4' => 'mp4',
            'convert_mkv' => 'mkv',
            'convert_avi' => 'avi',
            'convert_m4v' => 'm4v'
        ];
        
        if (isset($format_map[$data])) {
            $format = $format_map[$data];
            $bot->update_user_state('convert_format', $format);
            $bot->update_user_state('convert_mode', 'format');
            
            $text = "✅ <b>Convert To " . strtoupper($format) . " Mode Activated!</b>\n\n"
                  . "Now send me any video file and I'll convert it to " . strtoupper($format) . " format.\n\n"
                  . "<b>Note:</b> Conversion may take time depending on video size.";
            
            $bot->edit_message($chat_id, $message_id, $text);
            $bot->answer_callback_query($callback_id, "Convert to " . strtoupper($format) . " activated");
        }
        elseif ($data === 'convert_as_video') {
            $bot->update_user_state('convert_mode', 'as_video');
            $text = "✅ <b>Convert as Video Mode Activated!</b>\n\n"
                  . "Now send me any file and I'll convert and upload it as VIDEO.\n\n"
                  . "<b>Note:</b> File will be uploaded as video stream.";
            
            $bot->edit_message($chat_id, $message_id, $text);
            $bot->answer_callback_query($callback_id, "Convert as video activated");
        }
        elseif ($data === 'convert_as_file') {
            $bot->update_user_state('convert_mode', 'as_file');
            $text = "✅ <b>Convert as File Mode Activated!</b>\n\n"
                  . "Now send me any file and I'll convert and upload it as DOCUMENT.\n\n"
                  . "<b>Note:</b> File will be uploaded as document.";
            
            $bot->edit_message($chat_id, $message_id, $text);
            $bot->answer_callback_query($callback_id, "Convert as file activated");
        }
    }
}

function process_file($bot, $message, $file_type) {
    $chat_id = $message['chat']['id'];
    $user_state = $bot->get_user_state();
    
    $bot->send_message($chat_id, "📥 <b>File received! Processing...</b>");
    
    if ($file_type === 'document') {
        $file = $message['document'];
        $file_id = $file['file_id'];
        $original_name = $file['file_name'] ?? 'unknown_file';
    } else {
        $file = $message['video'];
        $file_id = $file['file_id'];
        $original_name = 'video.mp4';
    }
    
    // Download file
    $temp_file = 'temp/' . uniqid() . '_' . $original_name;
    if (!$bot->download_file($file_id, $temp_file)) {
        $bot->send_message($chat_id, "❌ <b>Failed to download file!</b>");
        return;
    }
    
    $file_size = human_readable(filesize($temp_file));
    list($md5, $sha1) = calc_checksum($temp_file);
    
    // Determine output filename
    $new_name = $user_state['new_name'] ?? $original_name;
    $output_file = 'uploads/' . $new_name;
    
    // Handle different modes
    $rename_mode = $user_state['rename_mode'] ?? null;
    $convert_mode = $user_state['convert_mode'] ?? null;
    $convert_format = $user_state['convert_format'] ?? null;
    
    $is_video = is_video_file($original_name);
    $final_file = $temp_file;
    $upload_as_video = $is_video;
    
    // Process based on mode
    if ($rename_mode === 'as_video') {
        $upload_as_video = true;
    } elseif ($rename_mode === 'as_file') {
        $upload_as_video = false;
    }
    
    if ($convert_mode === 'as_video') {
        $upload_as_video = true;
    } elseif ($convert_mode === 'as_file') {
        $upload_as_video = false;
    }
    
    // Handle video conversion
    if ($convert_format && $is_video) {
        $converted_file = 'temp/converted_' . uniqid() . '.' . $convert_format;
        $cmd = "ffmpeg -i " . escapeshellarg($temp_file) . " -c copy " . escapeshellarg($converted_file) . " 2>&1";
        exec($cmd, $output, $return_code);
        
        if ($return_code === 0 && file_exists($converted_file)) {
            $final_file = $converted_file;
            $new_name = pathinfo($new_name, PATHINFO_FILENAME) . '.' . $convert_format;
            $bot->send_message($chat_id, "✅ <b>Converted to " . strtoupper($convert_format) . " format!</b>");
        } else {
            $bot->send_message($chat_id, "⚠️ <b>Conversion failed, using original file</b>");
        }
    }
    
    // Generate thumbnail for videos
    $thumb_file = null;
    if ($upload_as_video && $is_video) {
        $thumb_file = 'temp/thumb_' . uniqid() . '.jpg';
        if (!generate_thumbnail($final_file, $thumb_file)) {
            $thumb_file = null;
        }
        
        // Use custom thumbnail if available
        if (file_exists('custom_thumb.jpg')) {
            $thumb_file = 'custom_thumb.jpg';
        }
    }
    
    // Prepare caption
    $caption = "📁 <b>File:</b> <code>" . htmlspecialchars($new_name) . "</code>\n"
             . "💾 <b>Size:</b> $file_size\n"
             . "🔒 <b>MD5:</b> <code>$md5</code>\n"
             . "🔒 <b>SHA1:</b> <code>$sha1</code>\n\n";
    
    // Add metadata if available
    $metadata = $user_state['metadata'] ?? [];
    if (!empty($metadata)) {
        $caption .= "📝 <b>Metadata:</b>\n";
        foreach ($metadata as $key => $value) {
            if (!empty($value)) {
                $caption .= "• <b>" . ucfirst($key) . ":</b> <code>$value</code>\n";
            }
        }
    }
    
    // Upload file
    $bot->send_message($chat_id, "⬆️ <b>Uploading file...</b>");
    
    if ($upload_as_video && $is_video) {
        $duration = get_video_duration($final_file);
        $dimensions = get_video_dimensions($final_file);
        
        $result = $bot->send_video(
            $chat_id, 
            $final_file, 
            $caption, 
            $thumb_file,
            $duration,
            $dimensions['width'],
            $dimensions['height']
        );
    } else {
        $result = $bot->send_document($chat_id, $final_file, $caption, $thumb_file);
    }
    
    if ($result) {
        $bot->send_message($chat_id, "✅ <b>File processed successfully!</b>");
        
        // Add to merge queue if in merge mode
        if (isset($user_state['merge_mode']) && $user_state['merge_mode'] && $is_video) {
            $merge_queue = $user_state['merge_queue'] ?? [];
            $merge_queue[] = $final_file;
            $bot->update_user_state('merge_queue', $merge_queue);
            $bot->send_message($chat_id, "📥 <b>Video added to merge queue!</b>\n\nTotal videos: " . count($merge_queue));
        }
    } else {
        $bot->send_message($chat_id, "❌ <b>Failed to upload file!</b>");
    }
    
    // Cleanup
    if (file_exists($temp_file)) {
        unlink($temp_file);
    }
    if (isset($converted_file) && file_exists($converted_file) && $converted_file !== $temp_file) {
        unlink($converted_file);
    }
    if ($thumb_file && file_exists($thumb_file) && $thumb_file !== 'custom_thumb.jpg') {
        unlink($thumb_file);
    }
}

function process_photo($bot, $message) {
    $chat_id = $message['chat']['id'];
    $user_state = $bot->get_user_state();
    
    if (isset($user_state['awaiting_thumb']) && $user_state['awaiting_thumb']) {
        $photos = $message['photo'];
        $largest_photo = end($photos); // Get the highest resolution photo
        $file_id = $largest_photo['file_id'];
        
        $bot->send_message($chat_id, "📥 <b>Downloading thumbnail...</b>");
        
        $thumb_file = 'custom_thumb.jpg';
        if ($bot->download_file($file_id, $thumb_file)) {
            $bot->update_user_state('awaiting_thumb', false);
            $bot->send_message($chat_id, "✅ <b>Custom thumbnail set successfully!</b>");
        } else {
            $bot->send_message($chat_id, "❌ <b>Failed to download thumbnail!</b>");
        }
    } else {
        $bot->send_message($chat_id, "🖼️ <b>Image received!</b>\n\nUse /setthumb if you want to set this as custom thumbnail.");
    }
}

// === WEBHOOK PROCESSING ===
function process_webhook($bot) {
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);
    
    if (!$update) {
        http_response_code(400);
        echo "Invalid update";
        return;
    }
    
    // Log the update for debugging (only in production)
    if (IS_PRODUCTION) {
        error_log("Webhook received: " . json_encode($update));
    }
    
    // Handle callback queries
    if (isset($update['callback_query'])) {
        $callback_query = $update['callback_query'];
        $user_id = $callback_query['from']['id'];
        
        if ($user_id == OWNER_ID) {
            handle_callback_query($bot, $callback_query);
        } else {
            $bot->answer_callback_query($callback_query['id'], "❌ Access denied! Only owner can use this bot.", true);
        }
        echo "OK";
        return;
    }
    
    // Handle messages
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        
        // Owner check
        if ($user_id != OWNER_ID) {
            $bot->send_message($chat_id, "❌ <b>Access Denied!</b> Only owner can use this bot.");
            echo "OK";
            return;
        }
        
        // Handle text commands
        if (isset($message['text'])) {
            $text = $message['text'];
            
            if (strpos($text, '/start') === 0 || strpos($text, '/help') === 0) {
                handle_start_help($bot, $chat_id);
            }
            elseif (strpos($text, '/video_renamer') === 0) {
                handle_video_renamer($bot, $chat_id);
            }
            elseif (strpos($text, '/video_converter') === 0) {
                handle_video_converter($bot, $chat_id);
            }
            elseif (strpos($text, '/merge_videos') === 0) {
                handle_merge_videos($bot, $chat_id);
            }
            elseif (strpos($text, '/merge_start') === 0) {
                handle_merge_start($bot, $chat_id);
            }
            elseif (strpos($text, '/merge_status') === 0) {
                handle_merge_status($bot, $chat_id);
            }
            elseif (strpos($text, '/merge_clear') === 0) {
                handle_merge_clear($bot, $chat_id);
            }
            elseif (strpos($text, '/setname') === 0) {
                handle_set_name($bot, $chat_id, $text);
            }
            elseif (strpos($text, '/clearname') === 0) {
                handle_clear_name($bot, $chat_id);
            }
            elseif (strpos($text, '/split_on') === 0) {
                handle_split_on($bot, $chat_id);
            }
            elseif (strpos($text, '/split_off') === 0) {
                handle_split_off($bot, $chat_id);
            }
            elseif (strpos($text, '/setthumb') === 0) {
                handle_set_thumb($bot, $chat_id);
            }
            elseif (strpos($text, '/view_thumb') === 0) {
                handle_view_thumb($bot, $chat_id);
            }
            elseif (strpos($text, '/del_thumb') === 0) {
                handle_del_thumb($bot, $chat_id);
            }
            elseif (strpos($text, '/status') === 0) {
                handle_status($bot, $chat_id);
            }
            elseif (strpos($text, '/metadata') === 0) {
                handle_metadata($bot, $chat_id, $text);
            }
            else {
                $bot->send_message($chat_id, "❌ Unknown command. Use /help for available commands.");
            }
        }
        // Handle documents
        elseif (isset($message['document'])) {
            process_file($bot, $message, 'document');
        }
        // Handle videos
        elseif (isset($message['video'])) {
            process_file($bot, $message, 'video');
        }
        // Handle photos (for thumbnails)
        elseif (isset($message['photo'])) {
            process_photo($bot, $message);
        }
    }
    
    echo "OK";
}

// === WEBHOOK SETUP ===
function setup_webhook($bot) {
    $webhook_url = BASE_URL . '/index.php';
    $result = $bot->api_request('setWebhook', ['url' => $webhook_url]);
    
    if ($result && $result['ok']) {
        error_log("✅ Webhook set successfully: " . $webhook_url);
        return true;
    } else {
        error_log("❌ Webhook setup failed: " . json_encode($result));
        return false;
    }
}

function delete_webhook($bot) {
    $result = $bot->api_request('deleteWebhook');
    
    if ($result && $result['ok']) {
        error_log("✅ Webhook deleted successfully");
        return true;
    } else {
        error_log("❌ Webhook deletion failed: " . json_encode($result));
        return false;
    }
}

// === INITIALIZATION ===
$bot = new TelegramBot(BOT_TOKEN);

// Initialize data files with proper permissions
$data_files = ['users.json', 'metadata.json', 'bot_state.json', 'error.log'];
foreach ($data_files as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, $file === 'error.log' ? '' : '{}');
        chmod($file, 0666);
    }
}

// Handle webhook setup if requested
if (isset($_GET['setup'])) {
    header('Content-Type: application/json');
    if (setup_webhook($bot)) {
        echo json_encode(['status' => 'success', 'message' => 'Webhook setup completed', 'url' => BASE_URL . '/index.php']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Webhook setup failed']);
    }
    exit;
}

// Handle webhook deletion if requested
if (isset($_GET['delete_webhook'])) {
    header('Content-Type: application/json');
    if (delete_webhook($bot)) {
        echo json_encode(['status' => 'success', 'message' => 'Webhook deleted successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Webhook deletion failed']);
    }
    exit;
}

// Health check endpoint
if (isset($_GET['health'])) {
    header('Content-Type: application/json');
    
    $status = [
        'status' => 'ok',
        'timestamp' => date('Y-m-d H:i:s'),
        'environment' => IS_PRODUCTION ? 'production' : 'development',
        'bot_username' => 'UltimateFileBot',
        'version' => '8.0',
        'owner' => 'MahatabAnsari786'
    ];
    
    // Check required directories
    $directories = ['uploads', 'temp'];
    foreach ($directories as $dir) {
        $status['directories'][$dir] = is_writable($dir) ? 'writable' : 'not_writable';
    }
    
    // Check required files
    $files = ['users.json', 'metadata.json', 'bot_state.json'];
    foreach ($files as $file) {
        $status['files'][$file] = file_exists($file) ? 'exists' : 'missing';
    }
    
    echo json_encode($status);
    exit;
}

// Clean old temp files (older than 1 hour)
if (isset($_GET['cleanup'])) {
    $temp_files = glob('temp/*');
    $cleaned = 0;
    $now = time();
    
    foreach ($temp_files as $file) {
        if (is_file($file) && ($now - filemtime($file)) > 3600) {
            unlink($file);
            $cleaned++;
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'cleaned_files' => $cleaned]);
    exit;
}

// Main webhook processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    process_webhook($bot);
} else {
    // Show info page for GET requests
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>🤖 Ultimate File Management Bot</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
            .container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            h1 { color: #333; border-bottom: 2px solid #007cba; padding-bottom: 10px; }
            .status { background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 15px 0; }
            .button { display: inline-block; background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 5px; }
            .button:hover { background: #005a87; }
            .section { margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🤖 Ultimate File Management Bot v8.0</h1>
            
            <div class="status">
                <strong>🚀 Status:</strong> <span style="color: green;">🟢 Running</span><br>
                <strong>📅 Version:</strong> 8.0<br>
                <strong>👤 Owner:</strong> @MahatabAnsari786<br>
                <strong>🌐 Host:</strong> Render.com<br>
                <strong>🕒 Started:</strong> <?php echo date('Y-m-d H:i:s'); ?>
            </div>
            
            <div class="section">
                <h3>🔧 Management Tools</h3>
                <a class="button" href="?setup=1">Setup Webhook</a>
                <a class="button" href="?delete_webhook=1">Delete Webhook</a>
                <a class="button" href="?health=1">Health Check</a>
                <a class="button" href="?cleanup=1">Clean Temp Files</a>
            </div>
            
            <div class="section">
                <h3>📋 Features</h3>
                <ul>
                    <li>Video files upload as VIDEO</li>
                    <li>Fixed Video Dimensions: <?php echo VIDEO_WIDTH; ?>×<?php echo VIDEO_HEIGHT; ?> pixels</li>
                    <li>Video merging capability</li>
                    <li>Video Renamer with options</li>
                    <li>Video Converter with multiple formats</li>
                    <li>Custom metadata support</li>
                    <li>Thumbnail management</li>
                    <li>MD5 & SHA1 checksum</li>
                    <li>File splitting (4GB chunks)</li>
                </ul>
            </div>
            
            <div class="section">
                <h3>🔗 Webhook Info</h3>
                <p><strong>URL:</strong> <code><?php echo BASE_URL . '/index.php'; ?></code></p>
                <p><strong>Method:</strong> POST</p>
                <p><strong>Environment:</strong> <?php echo IS_PRODUCTION ? 'Production 🚀' : 'Development'; ?></p>
            </div>
            
            <div class="section">
                <h3>📞 Contact</h3>
                <p>For support contact: @MahatabAnsari786</p>
            </div>
        </div>
    </body>
    </html>
    <?php
}
?>