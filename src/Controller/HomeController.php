<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tax\TaxRates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(TaxRates $taxRates): Response
    {
        return $this->render('home/index.html.twig', [
            'treaty_rates' => $taxRates->supportedRates(),
            'country_names' => $taxRates->supportedCountries(),
        ]);
    }

    #[Route('/skad-wziac-pliki', name: 'app_files_guide', methods: ['GET'])]
    public function filesGuide(): Response
    {
        return $this->render('guide/index.html.twig');
    }
}
