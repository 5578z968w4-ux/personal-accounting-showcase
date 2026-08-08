<?php

declare(strict_types=1);

final class AiPromptBuilder
{
    private const CATEGORIES = [
        '餐飲',
        '交通',
        '購物',
        '3C',
        '娛樂',
        '生活',
        '醫療',
        '薪資',
        '加班',
        '其他',
    ];

    /**
     * @param array<string, list<string>> $referenceData
     */
    public function build(string $inputText, string $requestedType, array $referenceData): string
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d');
        $typeInstruction = $requestedType === 'auto'
            ? '請自行判斷 type。'
            : 'type 必須為 ' . $requestedType . '。';

        return implode("\n", [
            '你是個人記帳系統的自然語言解析器，只能回傳符合指定 schema 的 JSON。',
            '今天日期（Asia/Taipei）：' . $today,
            $typeInstruction,
            '允許 type：expense、income、overtime、leave。',
            '日期一律輸出 YYYY-MM-DD。無法合理判斷時使用今天日期。',
            '輸入中的 M/D（例如 6/8）表示今年該月該日；不得忽略使用者輸入的日期。',
            '「7-11」是便利商店名稱，不是 7 月 11 日；未輸入日期時使用今天。',
            'expense：填 record_date、item、amount、payment_method、category。',
            '舊 iOS/GAS 支出簡寫若以付款方式結尾，例如「早餐80現金」，代表 item=早餐、amount=80、payment_method=現金。',
            '只有在尾端明確符合付款方式候選或付款語境時，才可把品項後方數字視為 amount；不得把品項名稱中的數字（例如 7-11）當成金額。',
            '支出簡寫例：「早餐1現金」=> item=早餐, amount=1, payment_method=現金；「午餐100現金」=> item=午餐, amount=100；「飲料35現金」=> item=飲料, amount=35。',
            'income：填 record_date、source_name、amount、account_name、category；帳戶可為空字串。',
            'category 只能使用：' . implode('、', self::CATEGORIES) . '。',
            '分類規則：PS5、Switch、手機、電腦、耳機、螢幕歸類為 3C。',
            '分類規則：便利商店、7-11、全家、早餐、午餐、晚餐、飲料歸類為餐飲。',
            '分類規則：捷運、高鐵、油錢、停車、計程車歸類為交通。',
            '分類規則：薪資、獎金歸類為薪資；無法判斷時使用其他。',
            'overtime：填 work_date、overtime_hours。',
            'leave：填 leave_date、leave_type、leave_days、leave_hours、note；使用者未指定假別時 leave_type 使用 null，不得自行猜測。',
            '非該類型欄位請使用 null，不得產生額外欄位。',
            '付款方式候選：' . $this->listText($referenceData['payment_methods'] ?? []),
            '收入帳戶候選：' . $this->listText($referenceData['accounts'] ?? []),
            '假別候選：' . $this->listText($referenceData['leave_types'] ?? []),
            '使用者輸入：' . $inputText,
        ]);
    }

    /** @return array<string, mixed> */
    public function responseSchema(): array
    {
        $nullableString = ['type' => 'string', 'nullable' => true];
        $nullableNumber = ['type' => 'number', 'nullable' => true];

        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['expense', 'income', 'overtime', 'leave']],
                'record_date' => $nullableString,
                'item' => $nullableString,
                'amount' => $nullableNumber,
                'payment_method' => $nullableString,
                'category' => [
                    'type' => 'string',
                    'enum' => self::CATEGORIES,
                    'nullable' => true,
                ],
                'source_name' => $nullableString,
                'account_name' => $nullableString,
                'work_date' => $nullableString,
                'overtime_hours' => $nullableNumber,
                'leave_date' => $nullableString,
                'leave_type' => $nullableString,
                'leave_days' => $nullableNumber,
                'leave_hours' => $nullableNumber,
                'note' => $nullableString,
            ],
            'required' => [
                'type',
                'record_date',
                'item',
                'amount',
                'payment_method',
                'category',
                'source_name',
                'account_name',
                'work_date',
                'overtime_hours',
                'leave_date',
                'leave_type',
                'leave_days',
                'leave_hours',
                'note',
            ],
        ];
    }

    /** @param list<string> $items */
    private function listText(array $items): string
    {
        return $items === [] ? '無' : implode('、', $items);
    }
}
