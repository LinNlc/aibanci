<?php
/**
 * File: api/ai_propose.php
 * Role: 调用 DeepSeek，根据当前排班数据生成“建议变更（ops）”
 * I/O : 仅接受 POST application/json，返回严格 JSON（包含 ops[] 与 note）
 * 安全: 不在前端暴露密钥；仅传员工 ID/工号与班次，不传真实姓名
 */

declare(strict_types=1);

if (function_exists('headers_sent') && !headers_sent()) {
  header('Content-Type: application/json; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: no-store');
}

// —— 仅允许 POST —— //
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  header('Allow: POST', true, 405);
  echo json_encode(['error' => 'Method Not Allowed']);
  exit;
}

// —— 读取密钥 —— //
$apiKey = getenv('DEEPSEEK_API_KEY') ?: '';
if ($apiKey === '') {
  http_response_code(500);
  echo json_encode(['error' => 'Missing env DEEPSEEK_API_KEY']);
  exit;
}

// —— 读取请求体 —— //
$raw = file_get_contents('php://input');
if ($raw === false) {
  http_response_code(400);
  echo json_encode(['error' => 'Cannot read request body']);
  exit;
}
if (strlen($raw) > 2 * 1024 * 1024) { // 2MB 保护
  http_response_code(413);
  echo json_encode(['error' => 'Payload too large']);
  exit;
}
$in = json_decode($raw, true);
if (!is_array($in)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid JSON']);
  exit;
}

// ====== 业务参数与模型选择 ====== //
$reqStart = (string)($in['start'] ?? '');
$reqEnd   = (string)($in['end'] ?? '');
$employees= is_array($in['employees'] ?? null) ? $in['employees'] : [];
$data     = is_array($in['data'] ?? null) ? $in['data'] : [];
$restPrefs= is_array($in['restPrefs'] ?? null) ? $in['restPrefs'] : [];
$nightRules = is_array($in['nightRules'] ?? null) ? $in['nightRules'] : ['minInterval'=>3, 'rest2AfterNight'=>['enabled'=>true]];
$midRatio = is_numeric($in['midRatio'] ?? null) ? (float)$in['midRatio'] : 0.5;
$mixedCycleMaxRatio = is_numeric($in['mixedCycleMaxRatio'] ?? null) ? (float)$in['mixedCycleMaxRatio'] : 0.25;
$userGoal = trim((string)($in['user_goal'] ?? $in['goal'] ?? '')); // ★ 读取你的输入框文本

$maxOps = isset($in['max_ops']) ? (int)$in['max_ops'] : 120;
$maxOps = max(10, min(400, $maxOps));

// 前端 mode: 'chat' | 'reason'
$reqMode = (string)($in['mode'] ?? 'chat');

// 粗略估算载荷体积（>160KB 时不建议 reasoner）
$payloadBytes = strlen(json_encode([
  'start'=>$reqStart, 'end'=>$reqEnd, 'employees'=>$employees, 'data'=>$data
], JSON_UNESCAPED_UNICODE));

$noteHints = [];
$chosenModel = ($reqMode === 'reason' && $payloadBytes <= 160*1024) ? 'deepseek-reasoner' : 'deepseek-chat';
if ($reqMode === 'reason' && $chosenModel !== 'deepseek-reasoner') {
  $noteHints[] = '请求体较大，已自动从 reasoner 降级为 chat 以避免超时';
}

// ====== 提示词与输入整理 ====== //
$system = <<<SYS
你是一个排班优化助手。你必须仅以 JSON 返回：
{"ops":[{"day":"YYYY-MM-DD","emp":"ID","to":"白|中1|中2|夜|休","why":""}], "note":""}
不要输出 JSON 以外的任何字符。
硬约束（必须满足）：
- 单人连续上班 ≤ 6 天；
- 夜班后休 2 天，且这 2 天内不得中2；
- 中2 次日必须休；
- 禁止出现相邻变更模式“中1→白”（允许 白→中1）；
- 保持每日各班位人数总量不变（优先人-人对调，不新增缺口）。
优化倾向（在不违反硬约束前提下）：
- 让选区内每人的“中1”数量尽量均衡；
- 若有休息偏好，尽量尊重；如无解，最小破例并在 why 里说明；
- 尽量在工作块边缘微调，尽量减少 ops 数量。
若 user_goal 非空：在不违反硬约束、且不改变每日各班位总量的前提下，尽可能满足 user_goal；无法完全满足时，请在 why/note 中说明取舍。
SYS;

$userPayload = [
  'start' => $reqStart,
  'end'   => $reqEnd,
  'employees' => $employees,
  'data'      => $data,
  'restPrefs' => $restPrefs,
  'nightRules'=> $nightRules,
  'midRatio'  => $midRatio,
  'mixedCycleMaxRatio' => $mixedCycleMaxRatio,
  'max_ops'   => $maxOps,
  'user_goal' => $userGoal,     // ★ 传给模型
];

// —— 强约束 JSON：使用“函数调用”工具 —— //
$tools = [[
  'type' => 'function',
  'function' => [
    'name' => 'propose_ops',
    'description' => '返回排班建议，必须严格是 JSON 参数',
    'parameters' => [
      'type' => 'object',
      'properties' => [
        'ops'  => [
          'type' => 'array',
          'items'=> [
            'type'=>'object',
            'properties'=>[
              'day'=>['type'=>'string'],
              'emp'=>['type'=>'string'],
              'to' =>['type'=>'string','enum'=>['白','中1','中2','夜','休']],
              'why'=>['type'=>'string']
            ],
            'required'=>['day','emp','to']
          ]
        ],
        'note' => ['type'=>'string']
      ],
      'required' => ['ops']
    ]
  ]
]];

$dsBody = [
  'model'    => $chosenModel,
  'messages' => [
    ['role'=>'system','content'=>$system],
    ['role'=>'user',  'content'=>json_encode($userPayload, JSON_UNESCAPED_UNICODE)],
  ],
  'tools'        => $tools,
  'tool_choice'  => ['type'=>'function','function'=>['name'=>'propose_ops']], // 强制走函数
  'response_format' => ['type'=>'json_object'],                                // 限定 JSON
  'temperature'=> 0.2,
  'max_tokens' => ($chosenModel === 'deepseek-reasoner') ? 1000 : 700,
  'stream'     => false
];

// ====== cURL 调用封装 ====== //
function ds_call(string $apiKey, array $body, int $timeoutSec = 180): array {
  $ch = curl_init('https://api.deepseek.com/chat/completions');
  $headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: Bearer ' . $apiKey
  ];
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => $timeoutSec,                // 总超时
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,          // 优先 IPv4
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,      // HTTP/1.1
    CURLOPT_TCP_KEEPALIVE  => 1,
    CURLOPT_TCP_KEEPIDLE   => 30,
    CURLOPT_TCP_KEEPINTVL  => 10,
  ]);
  $proxy = getenv('HTTPS_PROXY') ?: getenv('HTTP_PROXY');
  if ($proxy) curl_setopt($ch, CURLOPT_PROXY, $proxy);

  $raw  = curl_exec($ch);
  $errno= curl_errno($ch);
  $err  = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  return [$http, $errno, $err, $raw];
}

// —— 第一次调用 —— //
[$http, $errno, $err, $rawResp] = ds_call($apiKey, $dsBody, 180);

// —— 若超时/失败：自动降级为 chat 再试 —— //
if ($errno === 28 || !$rawResp || $http >= 500) {
  if ($dsBody['model'] !== 'deepseek-chat') {
    $noteHints[] = '上游计算缓慢或超时，已自动回退为 chat';
    $dsBody['model']      = 'deepseek-chat';
    $dsBody['max_tokens'] = 700;
    [$http, $errno, $err, $rawResp] = ds_call($apiKey, $dsBody, 180);
  }
}

// —— 仍失败：返回 502（带细节） —— //
if ($errno || !$rawResp) {
  http_response_code(502);
  echo json_encode([
    'error'  => 'Upstream error',
    'errno'  => $errno,
    'detail' => $err,
    'http'   => $http
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// ====== 解析响应（tool_calls → content → reasoning_content） ====== //
$respArr = json_decode($rawResp, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($respArr)) {
  echo json_encode([
    'ops'  => [],
    'note' => '上游返回非 JSON（已容错）；预览：' . substr((string)$rawResp, 0, 120)
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$choice    = $respArr['choices'][0] ?? [];
$message   = $choice['message'] ?? [];
$toolCalls = $message['tool_calls'] ?? null;

$out = null;

// 1) 函数调用（优先）
if (is_array($toolCalls) && isset($toolCalls[0]['function']['arguments'])) {
  $args = (string)$toolCalls[0]['function']['arguments'];
  $tmp  = json_decode($args, true);
  if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $out = $tmp;
}

// 2) 普通 content：提取最外层 JSON
if (!$out) {
  $content = (string)($message['content'] ?? '');
  if ($content !== '') {
    $s = strpos($content, '{'); $e = strrpos($content, '}');
    if ($s !== false && $e !== false && $e >= $s) {
      $jsonStr = substr($content, $s, $e - $s + 1);
      $tmp = json_decode($jsonStr, true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $out = $tmp;
    }
  }
}

// 3) reasoning_content 兜底
if (!$out) {
  $rc = (string)($message['reasoning_content'] ?? '');
  if ($rc !== '') {
    $s = strpos($rc, '{'); $e = strrpos($rc, '}');
    if ($s !== false && $e !== false && $e >= $s) {
      $jsonStr = substr($rc, $s, $e - $s + 1);
      $tmp = json_decode($jsonStr, true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $out = $tmp;
    }
  }
}

// 4) 仍无 JSON：返回空 ops + 说明
if (!$out || !is_array($out)) {
  $h = $noteHints ? '（'.implode('；',$noteHints).'）' : '';
  echo json_encode([
    'ops'  => [],
    'note' => '模型未返回可用 JSON。已启用函数调用约束；如仍为空，请改用 chat 模型或缩小范围后重试。' . $h
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// —— 规范输出 —— //
$ops  = is_array($out['ops'] ?? null) ? $out['ops'] : [];
$note = trim((string)($out['note'] ?? ''), "； \t\r\n");
if ($noteHints) {
  $note = $note ? ($note . '；' . implode('；', $noteHints)) : implode('；', $noteHints);
}

echo json_encode(['ops' => $ops, 'note' => $note], JSON_UNESCAPED_UNICODE);
exit;
