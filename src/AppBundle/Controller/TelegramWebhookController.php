<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Reservation;
use AppBundle\Telegram\MenuPublisher;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TelegramWebhookController extends Controller
{
    /**
     * This webhook endpoints receives all events (Messages, Bot added, ...) from Telegram.
     * @Route("/webhook")
     */
    public function webhookAction(Request $request)
    {
        $logger = $this->get('logger');
        $json = $request->getContent();
        $update = json_decode($json);

        $logger->info('Webhook request received:', [
            'method' => $request->getMethod(),
            'headers' => $request->headers
        ]);

        if (property_exists($update, 'callback_query')) {
            $this->handleDishChosen($update->callback_query);
        }

        $logger->info(json_encode($update));
        return new Response(Response::HTTP_NO_CONTENT);
    }

    private function handleDishChosen($callbackQuery)
    {
        $logger = $this->get('logger');
        $user = $callbackQuery->from->id;
        $parts = explode('_', $callbackQuery->data);
        $dish = $parts[0];
        $date = $parts[1];
        $cancelDish = $dish === 'none';
        $oldReservation = $this->findOldReservation($user, $date);
        $notificationText = 'Hab ich nicht kapiert :/';
        if ($oldReservation) {
            $logger->info("user ${user} updating reservation.", [
                'oldReservation' => $oldReservation
            ]);
        }
        if ($cancelDish) {
            $logger->info("user ${user} canceled their reservation for ${date}.");
            if (!$oldReservation) {
                $notificationText = 'Okay, vielleicht beim nächsten Mal :)';
            } else {
                $notificationText = 'Okay, ich habe dein Gericht abbestelt.';
                $this->deleteReservation($oldReservation);
            }
        } else {
            $logger->info("user ${user} reserved dish ${dish} for ${date}.");
            if ($oldReservation && $oldReservation->getDish() != $dish) {
                $notificationText = 'Okay, ich habe deine Reservierung aktualisiert!';
                $this->deleteReservation($oldReservation);
            } elseif ($oldReservation) {
                $notificationText = 'Das hatte ich dir schon bestellt. Scheinst dich ja sehr darauf zu freuen! :)';
            } else {
                $notificationText = 'Cool, dein Gericht ist bestellt!';
                $this->recordReservation($user, $date, $dish);
            }
        }
        $menuPublisher = new MenuPublisher($logger);
        $menuPublisher->answerCallbackQuery($callbackQuery, $notificationText);
    }

    private function findOldReservation($user, $date)
    {
        $repository = $this->getDoctrine()
            ->getRepository('AppBundle:Reservation');

        $query = $repository->createQueryBuilder('r')
            ->where('r.userId = :userId AND r.menuDate = :menuDate')
            ->setParameter('userId', $user)
            ->setParameter('menuDate', $date)
            ->getQuery();

        return $query->setMaxResults(1)->getOneOrNullResult();
    }

    private function recordReservation($user, $date, $dish)
    {
        $reservation = new Reservation();
        $reservation->setUserId($user);
        $reservation->setMenuDate($date);
        $reservation->setDish($dish);
        $em = $this->getDoctrine()->getManager();
        $em->persist($reservation);
        $em->flush();
    }

    private function deleteReservation($oldReservation)
    {
        $em = $this->getDoctrine()->getManager();
        $em->remove($oldReservation);
        $em->flush();
    }
}
