<?php

namespace App\Services\Marketing\Email;

interface EmailProvider
{
    public function key(): string;

    public function label(): string;

    /**
     * @param array{
     *   to_email:string,
     *   subject:string,
     *   text:?string,
     *   html:?string,
     *   from_email:?string,
     *   from_name:?string,
     *   reply_to_email:?string,
     *   headers:array<string,string>,
     *   metadata:array<string,mixed>,
     *   custom_args:array<string,mixed>,
     *   categories:array<int,string>,
     *   dry_run:bool
     * } $message
     * @param array<string,mixed> $config
     * @return array{
     *   success:bool,
     *   provider:string,
     *   status:string,
     *   message_id:?string,
     *   error_code:?string,
     *   error_message:?string,
     *   retryable:bool,
     *   payload:array<string,mixed>,
     *   dry_run:bool
     * }
     */
    public function sendEmail(array $message, array $config = []): array;

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $context
     * @return array{
     *   success:bool,
     *   provider:string,
     *   status:string,
     *   message_id:?string,
     *   error_code:?string,
     *   error_message:?string,
     *   retryable:bool,
     *   payload:array<string,mixed>,
     *   dry_run:bool
     * }
     */
    public function sendTestEmail(string $toEmail, array $config = [], array $context = []): array;

    /**
     * @param array<string,mixed> $config
     * @return array{valid:bool,status:string,issues:array<int,string>,details:array<string,mixed>}
     */
    public function validateConfiguration(array $config = []): array;

    /**
     * @param array<string,mixed> $config
     * @return array{status:string,message:string,details:array<string,mixed>}
     */
    public function getHealthStatus(array $config = []): array;
}
