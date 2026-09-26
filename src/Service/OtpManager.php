<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Code à usage unique envoyé par e-mail (paramètre « OTP » de l'admin).
 *
 * Seule une empreinte HMAC du code est gardée en session, avec son
 * échéance et le nombre d'essais : un code vaut pour un seul usage
 * (`purpose`), expire au bout de 10 minutes et est invalidé après 5 essais
 * manqués.
 */
class OtpManager
{
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_PASSWORD = 'password';

    private const TTL_SECONDS = 600;
    private const MAX_ATTEMPTS = 5;
    private const RESEND_DELAY_SECONDS = 30;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly MailerInterface $mailer,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $mailerDsn,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    public function send(User $user, string $purpose): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);

        $this->requestStack->getSession()->set($this->key($purpose), [
            'hash' => $this->hash($code),
            'expiresAt' => time() + self::TTL_SECONDS,
            'sentAt' => time(),
            'attempts' => 0,
        ]);

        $this->mailer->send((new TemplatedEmail())
            ->from(Address::create($this->mailerFrom))
            ->to($user->getEmail())
            ->subject($purpose === self::PURPOSE_LOGIN ? 'Votre code de connexion' : 'Confirmez le changement de votre mot de passe')
            ->htmlTemplate('emails/otp.html.twig')
            ->context([
                'code' => $code,
                'purpose' => $purpose,
                'ttlMinutes' => intdiv(self::TTL_SECONDS, 60),
            ]));

        // Sans transport réel en développement, le code n'arriverait jamais :
        // on l'affiche pour pouvoir tester le parcours.
        $session = $this->requestStack->getSession();
        if ($this->environment === 'dev' && str_starts_with($this->mailerDsn, 'null://') && $session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('info', sprintf('Développement (aucun e-mail envoyé) : le code est %s.', $code));
        }
    }

    public function verify(string $purpose, string $code): OtpResult
    {
        $session = $this->requestStack->getSession();
        $state = $session->get($this->key($purpose));

        if (!\is_array($state)) {
            return OtpResult::EXPIRED;
        }

        if (time() > $state['expiresAt']) {
            $session->remove($this->key($purpose));

            return OtpResult::EXPIRED;
        }

        if (hash_equals($state['hash'], $this->hash(trim($code)))) {
            $session->remove($this->key($purpose));

            return OtpResult::VALID;
        }

        ++$state['attempts'];
        if ($state['attempts'] >= self::MAX_ATTEMPTS) {
            $session->remove($this->key($purpose));

            return OtpResult::TOO_MANY_ATTEMPTS;
        }

        $session->set($this->key($purpose), $state);

        return OtpResult::INVALID;
    }

    /**
     * Secondes à attendre avant de pouvoir renvoyer un code (0 si possible).
     */
    public function resendWait(string $purpose): int
    {
        $state = $this->requestStack->getSession()->get($this->key($purpose));

        return \is_array($state) ? max(0, $state['sentAt'] + self::RESEND_DELAY_SECONDS - time()) : 0;
    }

    public function clear(string $purpose): void
    {
        $this->requestStack->getSession()->remove($this->key($purpose));
    }

    private function key(string $purpose): string
    {
        return 'otp.' . $purpose;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, $this->secret);
    }
}
