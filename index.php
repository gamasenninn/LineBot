<?php

$config = parse_ini_file('./config.ini', true);
$apiConfig = $config['api'];
$accessToken =  $apiConfig['linekey'];
$whisperApiKey = $apiConfig['openai_key'];

//ユーザーからのメッセージ取得
$json_string = file_get_contents('php://input');
$json_object = json_decode($json_string);
 
//取得データ
$replyToken = $json_object->{"events"}[0]->{"replyToken"};        //返信用トークン
$message_type = $json_object->{"events"}[0]->{"message"}->{"type"};    //メッセージタイプ
$message_text = $json_object->{"events"}[0]->{"message"}->{"text"};    //メッセージ内容
 
//メッセージタイプが「text」以外のときは何も返さず終了
if($message_type == "text"){
 
	//返信メッセージ
	$return_message_text = "「" . $message_text . "」ですね";
 
	//返信実行
	sending_messages($accessToken, $replyToken, $message_type, $return_message_text);
}
elseif($message_type == "audio"){
    //オーディオメッセージの場合の返信（durationを取得して返信）
    $audio_duration_ms = $json_object->{"events"}[0]->{"message"}->{"duration"};
    $audio_duration_seconds = $audio_duration_ms / 1000;  // 秒単位に変換
    $return_message_text = "音声データを検知しました。: " . $audio_duration_seconds . "秒です。";

    // 音声の保存
    $messageId = $json_object->{"events"}[0]->{"message"}->{"id"};
    $filePath = $messageId.".mp3"; // 保存するファイルのパス
    if(saveAudioContent($messageId, $accessToken, $filePath)){

        $transcription = transcribeAudioWithWhisper($filePath, $whisperApiKey);
        sending_messages($accessToken, $replyToken, "text", $return_message_text."\n-------\n".$transcription);
    }
    else {
        sending_messages($accessToken, $replyToken, "text", $return_message_text."\n音声を保存できませんでした。");
    }
}
else{
	exit;
}
?>
<?php
//メッセージの送信
function sending_messages($accessToken, $replyToken, $message_type, $return_message_text){
    //レスポンスフォーマット
    $response_format_text = array(
        "type" => $message_type,
        "text" => $return_message_text
    );
 
    //ポストデータ
    $post_data = array(
        "replyToken" => $replyToken,
        "messages" => array($response_format_text)
    );
 
    //curl実行
    $ch = curl_init("https://api.line.me/v2/bot/message/reply");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json; charser=UTF-8',
        'Authorization: Bearer ' . $accessToken
    ));
    $result = curl_exec($ch);
    curl_close($ch);
}
// オーディオファイルの保存
function saveAudioContent($messageId, $accessToken, $filePath) {
    // LINE Messaging APIのURL
    $url = "https://api-data.line.me/v2/bot/message/$messageId/content";

    // cURLセッションを初期化
    $ch = curl_init($url);

    // HTTPヘッダを設定（アクセストークンを含む）
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $accessToken
    ));

    // 戻り値を文字列として受け取る
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // GETリクエストを実行
    $audio_content = curl_exec($ch);

    // エラーチェック
    if(curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return false; // またはエラーメッセージを返す
    }

    // cURLセッションを終了
    curl_close($ch);

    // ファイルにコンテンツを書き込む
    if(file_put_contents($filePath, $audio_content) === false) {
        return false; // ファイル書き込みエラー
    }

    return true;
}

// whisper api
function transcribeAudioWithWhisper($audioFilePath, $whisperApiKey) {
    // Whisper APIのエンドポイント
    $whisperApiUrl = 'https://api.openai.com/v1/audio/transcriptions';

    // cURLセッションを初期化
    $ch = curl_init($whisperApiUrl);

    // cURLオプションの設定
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $whisperApiKey,
        'Content-Type: multipart/form-data'
    ));

    // 送信する音声ファイルとモデルの設定
    //$cfile = curl_file_create($audioFilePath);
    $data = array(
        'file' => '@' . realpath($audioFilePath),
        'model' => 'whisper-1'
    );

    // POSTデータの設定
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    // APIリクエストの実行
    $response = curl_exec($ch);

    // 出力バッファリングを開始
    ob_start();
    var_dump($response);
    // 出力バッファの内容を取得し、バッファをクリア
    $responseDump = ob_get_clean();

    // エラーチェック
    if(curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return "エラー発生: " . $error_msg . "\nAPIレスポンス: " . $responseDump;
    }
    // cURLセッションを終了
    curl_close($ch);

    // APIレスポンスをデコード
    $responseData = json_decode($response, true);

    // 文字起こしの結果を返す
    return isset($responseData['text']) ? $responseData['text'] : "文字がセットされてないね";
}



?>