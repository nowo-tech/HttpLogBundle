<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

/**
 * Personal-data erasure (GDPR art. 17) of one subject's HTTP log entries, beside the count / delete methods of
 * {@see HttpLogPersonalDataEraserInterface} (unchanged; this port delegates to it, so exact matching and batching are the
 * same).
 *
 * Transactions: when no transaction is open on the bundle's connection, the call opens one, commits it and then
 * dispatches {@see HttpLogPersonalDataErasedEvent}. Inside the caller's open transaction (UserKit runs every eraser in
 * one) the deletes join it and no event is dispatched: the returned result is the outcome. Idempotent: a second run
 * returns zero counts.
 */
interface HttpLogPersonalDataErasureInterface
{
    public function erase(HttpLogPersonalDataSubject $subject): HttpLogErasureResult;
}
