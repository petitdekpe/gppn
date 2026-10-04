<?php

namespace App\Twig;

use App\Util\FrenchDate;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Extension\CoreExtension;
use Twig\TwigFilter;

/**
 * Dates en français dans les gabarits (voir FrenchDate) : |date_fr, |date_fr_short, |datetime_fr.
 * Même conversion de fuseau que le filtre |date de Twig.
 */
class DateExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('date_fr', fn (Environment $env, mixed $date) => FrenchDate::date($this->convert($env, $date)), ['needs_environment' => true]),
            new TwigFilter('date_fr_short', fn (Environment $env, mixed $date) => FrenchDate::shortDate($this->convert($env, $date)), ['needs_environment' => true]),
            new TwigFilter('datetime_fr', fn (Environment $env, mixed $date) => FrenchDate::dateTime($this->convert($env, $date)), ['needs_environment' => true]),
        ];
    }

    private function convert(Environment $env, mixed $date): \DateTimeInterface
    {
        return $env->getExtension(CoreExtension::class)->convertDate($date);
    }
}
