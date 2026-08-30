<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Aws\Exception\AwsException;
use Aws\Sns\SnsClient;
use Illuminate\Support\Facades\Log;

// Provider: Amazon SNS SMS (https://aws.amazon.com/sns/sms/)
class AmazonSnsDriver implements SmsDriverInterface
{
    private SnsClient $client;

    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $senderId = '',
        private readonly string $smsType = 'Transactional',
    ) {
        $this->client = new SnsClient([
            'version'     => 'latest',
            'region'      => $this->region ?: 'us-east-1',
            'credentials' => [
                'key'    => $this->accessKey,
                'secret' => $this->secretKey,
            ],
        ]);
    }

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        try {
            $params = [
                'PhoneNumber'       => $to,
                'Message'           => $body,
                'MessageAttributes' => [
                    'AWS.SNS.SMS.SMSType' => [
                        'DataType'    => 'String',
                        'StringValue' => $this->smsType,
                    ],
                ],
            ];

            $senderId = $opts['from'] ?? $this->senderId;
            if ($senderId) {
                $params['MessageAttributes']['AWS.SNS.SMS.SenderID'] = [
                    'DataType'    => 'String',
                    'StringValue' => $senderId,
                ];
            }

            $result = $this->client->publish($params);

            return new SmsSendResult(true, $result->get('MessageId') ?? '');
        } catch (AwsException $e) {
            $error = $e->getAwsErrorMessage() ?? $e->getMessage();
            Log::error('Amazon SNS SMS error', ['to' => $to, 'code' => $e->getAwsErrorCode(), 'error' => $error]);
            return new SmsSendResult(false, '', $error);
        } catch (\Throwable $e) {
            // Catches CredentialsException, InvalidArgumentException, etc.
            Log::error('Amazon SNS unexpected error', ['to' => $to, 'error' => $e->getMessage()]);
            return new SmsSendResult(false, '', $e->getMessage());
        }
    }

    public function status(string $providerId): SmsStatus
    {
        // SNS does not expose a pull API for SMS delivery status
        return new SmsStatus($providerId, 'sent');
    }
}
