<?php

namespace App\Sales\Application;

use App\Audit\Application\AuditWriter;
use App\Sales\Domain\DealRepository;
use App\Sales\Domain\EventOutbox;
use App\Sales\Domain\IntegrationEvent;
use App\Sales\Domain\ProspectRepository;
use App\Shared\Domain\BusinessRule;
use App\Shared\Domain\UnitOfWork;

final class ManagePayments
{
    private const FLOW = ['BELUM_DITAGIH', 'INVOICE', 'TERTAGIH', 'LUNAS', 'OVERDUE'];

    public function __construct(private DealRepository $deals, private ProspectRepository $prospects,
        private UnitOfWork $transactions, private AuditWriter $audit, private EventOutbox $outbox) {}

    public function change(string $actorId, string $dealId, string $to): array
    {
        return $this->transactions->run(function () use ($actorId, $dealId, $to) {
            if (! in_array($to, self::FLOW, true)) {
                throw new BusinessRule('INVALID_PAYMENT_STATUS', 'Status pembayaran tidak dikenali.', 422);
            }
            $deal = $this->deals->find($dealId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $from = $deal->paymentStatus;
            if ($from === $to) {
                return [$deal, false];
            }
            $deal->paymentStatus = $to;
            $deal->version++;
            $this->deals->save($deal);
            $this->deals->history($dealId, $from, $to, $actorId);
            $prospect = $this->prospects->find($deal->prospectId, true);
            if ($prospect) {
                $prospect->paymentStatus = $to;
                $this->prospects->save($prospect, $actorId);
            }
            $this->audit->record('deal.payment_changed', $actorId, $dealId, ['paymentStatus' => $from], ['paymentStatus' => $to], 'deal');
            $this->outbox->publish(new IntegrationEvent('sales.payment_status.changed', 'deal', $dealId, $actorId, ['paymentStatus' => $to]));

            return [$deal, true];
        });
    }
}
