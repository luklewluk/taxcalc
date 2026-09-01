<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the sanitized sample CSVs.
 *
 * The requested name is matched against a fixed allow-list rather than being
 * joined onto a directory, so no traversal or arbitrary-path read is possible
 * even if the route pattern were loosened later.
 */
final class ExampleFileController extends AbstractController
{
    /**
     * Public name => file on disk, plus the description used on the calculator page.
     *
     * @var array<string, array{string, string}>
     */
    public const array FILES = [
        'pozycje-zamkniete.csv' => ['pozycje-zamkniete.csv', 'Format własny - pozycje zamknięte'],
        'dywidendy.csv' => ['dywidendy.csv', 'Format własny - dywidendy'],
        'ibkr-transakcje.csv' => ['ibkr-transakcje.csv', 'IBKR - transakcje giełdowe (Flex)'],
        'ibkr-dywidendy-aktywnosc.csv' => ['ibkr-dywidendy-aktywnosc.csv', 'IBKR - dywidendy (aktywność)'],
        'ibkr-dywidendy-detail.csv' => ['ibkr-dywidendy-detail.csv', 'IBKR - dywidendy (Dividend Detail)'],
        'degiro-transakcje.csv' => ['degiro-transakcje.csv', 'DEGIRO - transakcje (Transactions)'],
        'degiro-rachunek.csv' => ['degiro-rachunek.csv', 'DEGIRO - zestawienie konta (Account statement)'],
    ];

    public function __construct(private readonly string $examplesDir)
    {
    }

    #[Route(
        '/przyklady/{filename}',
        name: 'app_example_file',
        requirements: ['filename' => '[a-z0-9\-]+\.csv'],
        methods: ['GET'],
    )]
    public function download(string $filename): Response
    {
        $entry = self::FILES[$filename] ?? null;
        if (null === $entry) {
            throw new NotFoundHttpException('Nie ma takiego pliku przykładowego.');
        }

        $path = $this->examplesDir.'/'.$entry[0];
        $content = is_file($path) ? file_get_contents($path) : false;

        if (false === $content) {
            throw new NotFoundHttpException('Nie ma takiego pliku przykładowego.');
        }

        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition('attachment', $filename),
        );

        return $response;
    }
}
