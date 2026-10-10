<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\Mail;
use Phattarachai\MailLogLaravel\Concerns\HasMailLog;
use Phattarachai\MailLogLaravel\Enums\MailLogStatus;
use Phattarachai\MailLogLaravel\Listeners\LogOutgoingMail;
use Phattarachai\MailLogLaravel\Models\MailLog;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    Relation::morphMap([], merge: false);
    MailLogGroup::registerMorphMap();

    config()->set('mail.default', 'array');
    config()->set('mail.mailers.array', ['transport' => 'array']);
    config()->set('mail.from', ['address' => 'app@example.com', 'name' => 'App']);
});

function lastTransportedEmail(): Email
{
    return app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
}

it('removes every X-Mail-* log header before the message reaches the transport', function (): void {
    Mail::to('a@example.com')->send(new HeaderOrderMail(MailLogGroup::factory()->create(), withExtras: true));

    $headers = lastTransportedEmail()->getHeaders();

    foreach (LogOutgoingMail::LOG_HEADERS as $name) {
        expect($headers->has($name))->toBeFalse("{$name} reached the transport");
    }

    expect(MailLog::query()->sole()->status)->toBe(MailLogStatus::Sent);
});

it('stores the Message-ID the transport sent and matches MessageSent on it', function (): void {
    Mail::to('a@example.com')->send(new HeaderOrderMail(MailLogGroup::factory()->create()));

    $sentId = lastTransportedEmail()->getHeaders()->get('Message-ID')->getId();
    $row = MailLog::query()->sole();

    expect($row->message_id)->toBe($sentId)
        ->and($row->status)->toBe(MailLogStatus::Sent)
        ->and($row->seconds)->toBeFloat();
});

it('keeps a Message-ID the host set itself', function (): void {
    Mail::to('a@example.com')->send(new HeaderOrderMail(MailLogGroup::factory()->create(), messageId: 'order-42@shop.test'));

    expect(lastTransportedEmail()->getHeaders()->get('Message-ID')->getId())->toBe('order-42@shop.test')
        ->and(MailLog::query()->sole()->message_id)->toBe('order-42@shop.test');
});

it('stamps no X-Mail-* headers when Mail Log is disabled', function (): void {
    config()->set('mail-log.enabled', value: false);

    $headers = new HeaderOrderMail(MailLogGroup::factory()->create())->headers();

    expect($headers->text)->toBe([]);
});

class HeaderOrderMail extends Mailable
{
    use HasMailLog;

    public function __construct(public Model $order, public bool $withExtras = false, public ?string $messageId = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Header order');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Header order</p>');
    }

    public function headers(): Headers
    {
        return $this->withMailLog(new Headers(messageId: $this->messageId));
    }

    protected function mailLogModel(): ?Model
    {
        return $this->order;
    }

    protected function mailLogModels(): iterable
    {
        return $this->withExtras ? [$this->order] : [];
    }

    protected function mailLogFingerprintHints(): array
    {
        return ['tenant' => 7];
    }
}
