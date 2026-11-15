<?php

namespace Laravel\Horizon\Tests\Feature;

use Laravel\Horizon\SignalMailbox;
use Laravel\Horizon\Tests\IntegrationTest;

class SignalMailboxTest extends IntegrationTest
{
    public function test_signals_are_drained_in_the_order_they_were_sent()
    {
        $mailbox = resolve(SignalMailbox::class);

        $mailbox->send('pid:123', SIGUSR2);
        $mailbox->send('pid:123', SIGCONT);

        $this->assertSame([SIGUSR2, SIGCONT], $mailbox->drain('pid:123'));
        $this->assertSame([], $mailbox->drain('pid:123'));
    }

    public function test_signals_are_only_delivered_to_their_address()
    {
        $mailbox = resolve(SignalMailbox::class);

        $mailbox->send('pid:123', SIGTERM);

        $this->assertSame([], $mailbox->drain('pid:456'));
        $this->assertSame([SIGTERM], $mailbox->drain('pid:123'));
    }

    public function test_mailbox_can_be_reset()
    {
        $mailbox = resolve(SignalMailbox::class);

        $mailbox->send('id:abc', SIGTERM);
        $mailbox->reset('id:abc');

        $this->assertSame([], $mailbox->drain('id:abc'));
    }

    public function test_undelivered_signals_expire()
    {
        $mailbox = resolve(SignalMailbox::class);

        $mailbox->send('pid:123', SIGTERM);

        $ttl = $mailbox->redis->connection('horizon')->ttl('signals:pid:123');

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual($mailbox->ttl, $ttl);
    }
}
