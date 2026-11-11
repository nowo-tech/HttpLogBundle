<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Privacy;

use Nowo\HttpLogBundle\Privacy\HttpLogErasedSubjects;
use Nowo\HttpLogBundle\Privacy\HttpLogErasureResult;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataErasureInterface;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataExporterInterface;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataSubject;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataSubjectResolverInterface;
use Nowo\HttpLogBundle\Privacy\UserIdentifierSubjectResolver;
use Nowo\HttpLogBundle\Privacy\UserKit\HttpLogUserKitExportProvider;
use Nowo\HttpLogBundle\Privacy\UserKit\HttpLogUserKitPersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function str_repeat;

/**
 * GDPR ports (1.18): the UserKit eraser erases each distinct subject once (original identifier, previous e-mail), the
 * export section exports each subject, and the default resolver uses the identifier.
 */
final class UserKitBridgeTest extends TestCase
{
    #[Test]
    public function theDefaultResolverUsesTheOriginalIdentifierThenTheCurrentOne(): void
    {
        $resolver = new UserIdentifierSubjectResolver();
        $user     = new InMemoryUser('new@example.test', null);

        self::assertSame(['old@example.test'], $this->values($resolver->subjectsFor($user, 'old@example.test')));
        self::assertSame(['new@example.test'], $this->values($resolver->subjectsFor($user, null)));
        self::assertSame([], $resolver->subjectsFor(new stdClass(), null), 'not a UserInterface');
        self::assertSame([], $resolver->subjectsFor($user, str_repeat('a', 256)), 'the column could not hold it');
    }

    #[Test]
    public function theEraserErasesEachDistinctSubjectOnce(): void
    {
        $erasure = $this->erasure();
        $eraser  = new HttpLogUserKitPersonalDataEraser($erasure, new UserIdentifierSubjectResolver());
        $user    = new InMemoryUser('anon-1', null);

        self::assertSame('http_log', $eraser->getKey());
        self::assertSame(100, HttpLogUserKitPersonalDataEraser::PRIORITY);
        self::assertTrue($eraser->supports(new stdClass()));

        $eraser->erase(new ErasureRequest($user, null, ['previous_email' => 'old@example.test'], 'ana@example.test'));
        $eraser->erase(new ErasureRequest($user, null, ['previous_email' => 'ana@example.test'], 'ana@example.test'));
        $eraser->erase(new ErasureRequest($user, null, ['previous_email' => '']));
        $eraser->erase(new ErasureRequest($user, null, ['previous_email' => str_repeat('a', 256)]));
        $eraser->erase(new ErasureRequest($user, null, ['previous_email' => ['not a string']]));

        self::assertSame(['ana@example.test', 'old@example.test', 'ana@example.test', 'anon-1', 'anon-1', 'anon-1'], $erasure->erased);
    }

    #[Test]
    public function aSelfServiceErasureDropsTheCurrentRequestsCapture(): void
    {
        $erased = new HttpLogErasedSubjects();
        $eraser = new HttpLogUserKitPersonalDataEraser($this->erasure(), new UserIdentifierSubjectResolver(), $erased);
        $user   = new InMemoryUser('ana', null);

        $eraser->erase(new ErasureRequest($user, new InMemoryUser('admin', null)));
        self::assertFalse($erased->dropsCurrentRequest(), 'an admin erasing someone else keeps their own request log');

        $eraser->erase(new ErasureRequest($user, $user));
        self::assertTrue($erased->dropsCurrentRequest(), 'LY-L2: the person\'s own request is not logged after the erasure');
    }

    #[Test]
    public function theExportSectionExportsEachSubject(): void
    {
        $exporter = new class implements HttpLogPersonalDataExporterInterface {
            public function export(HttpLogPersonalDataSubject $subject): array
            {
                return ['subject' => $subject->toArray(), 'total' => 0, 'limit' => 1, 'truncated' => false, 'client_ip_windows' => [], 'entries' => []];
            }

            public function clientIpWindows(string $userIdentifier): array
            {
                return [];
            }
        };
        $twoSubjects = new class implements HttpLogPersonalDataSubjectResolverInterface {
            public function subjectsFor(object $user, ?string $originalIdentifier): array
            {
                return [HttpLogPersonalDataSubject::fromUserIdentifier('a'), HttpLogPersonalDataSubject::fromUserIdentifier('b')];
            }
        };

        $provider = new HttpLogUserKitExportProvider($exporter, new UserIdentifierSubjectResolver());
        self::assertSame('http_requests', $provider->getSectionKey());
        self::assertSame(-50, HttpLogUserKitExportProvider::PRIORITY);
        self::assertTrue($provider->supports(new InMemoryUser('ana', null)));
        self::assertFalse($provider->supports(new stdClass()));
        self::assertSame([['type' => 'user_identifier', 'value' => 'ana']], array_column($provider->export(new InMemoryUser('ana', null)), 'subject'));
        self::assertCount(2, (new HttpLogUserKitExportProvider($exporter, $twoSubjects))->export(new stdClass()));
    }

    /**
     * @param list<HttpLogPersonalDataSubject> $subjects
     *
     * @return list<string>
     */
    private function values(array $subjects): array
    {
        return array_map(static fn (HttpLogPersonalDataSubject $s): string => $s->value, $subjects);
    }

    /** @return HttpLogPersonalDataErasureInterface&object{erased: list<string>} */
    private function erasure(): HttpLogPersonalDataErasureInterface
    {
        return new class implements HttpLogPersonalDataErasureInterface {
            /** @var list<string> */
            public array $erased = [];

            public function erase(HttpLogPersonalDataSubject $subject): HttpLogErasureResult
            {
                $this->erased[] = $subject->value;

                return new HttpLogErasureResult();
            }
        };
    }
}
