<?php

class PromptOptimizer
{
    /**
     * 根据模式获取系统指令
     */
    public static function getSystemInstruction(array $config, string $mode): string
    {
        $instructs = $config['prompt_instructs'] ?? [];
        if (isset($instructs[$mode]) && is_string($instructs[$mode]) && $instructs[$mode] !== '') {
            return $instructs[$mode];
        }
        if (isset($instructs['basic']) && is_string($instructs['basic'])) {
            return $instructs['basic'];
        }
        return '你是AI绘画提示词专家，请优化提示词并仅输出优化后的结果。';
    }

    /**
     * 调用 Gemini 进行提示词优化（返回完整结果，包含思考内容）
     *
     * @return array ['optimized_prompt' => string, 'thoughts' => array]
     */
    public static function optimizePromptWithThoughts(string $prompt, string $mode, array $config): array
    {
        $model = isset($config['prompt_optimize_model']) && is_string($config['prompt_optimize_model'])
            ? $config['prompt_optimize_model']
            : 'gemini-2.5-flash';

        $systemInstruction = self::getSystemInstruction($config, $mode);

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.6,
                'topP' => 0.9,
                'maxOutputTokens' => 2048
            ]
        ];

        $response = callGeminiApi($model, $payload, 120, 20);

        // 安全检查
        if (isset($response['promptFeedback']['blockReason']) && $response['promptFeedback']['blockReason'] !== 'BLOCK_REASON_UNSPECIFIED') {
            $reason = $response['promptFeedback']['blockReason'];
            $categories = [];
            if (isset($response['promptFeedback']['safetyRatings']) && is_array($response['promptFeedback']['safetyRatings'])) {
                foreach ($response['promptFeedback']['safetyRatings'] as $rating) {
                    if (isset($rating['category'])) {
                        $categories[] = $rating['category'];
                    }
                }
            }
            $detail = $categories ? ('，类别：' . implode('、', $categories)) : '';
            sendError('提示词被模型拒绝，原因：' . $reason . $detail, 400);
        }

        $finishReason = $response['candidates'][0]['finishReason'] ?? 'UNKNOWN';
        $finishMsg = $response['candidates'][0]['finishMessage'] ?? '';

        // 允许的完成原因：STOP（正常结束）、MAX_TOKENS（达到token限制但内容仍有效）、FINISH_REASON_UNSPECIFIED
        $acceptableReasons = ['STOP', 'MAX_TOKENS', 'FINISH_REASON_UNSPECIFIED'];
        if (!in_array($finishReason, $acceptableReasons, true)) {
            $msg = $finishMsg ?: $finishReason;
            sendError('提示词优化被中断: ' . $msg, 502);
        }

        $optimized = extractTextFromCandidates($response);
        if ($optimized === '') {
            sendError('提示词优化未返回结果', 502);
        }

        // 提取思考内容
        $thoughts = extractThoughtsFromResponse($response);

        return [
            'optimized_prompt' => SecurityUtils::sanitizeTextInput($optimized, 4000),
            'thoughts' => $thoughts
        ];
    }

    /**
     * 调用 Gemini 进行提示词优化（仅返回优化结果，向后兼容）
     */
    public static function optimizePrompt(string $prompt, string $mode, array $config): string
    {
        $result = self::optimizePromptWithThoughts($prompt, $mode, $config);
        return $result['optimized_prompt'];
    }
}
