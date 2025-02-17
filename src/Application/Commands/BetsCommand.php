<?php

namespace Chorume\Application\Commands;

use Discord\Discord;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Embed\Embed;
use Chorume\Repository\User;
use Chorume\Repository\Event;
use Chorume\Repository\EventBet;

class BetsCommand
{
    public $discord;
    public $config;
    public User $userRepository;
    public Event $eventRepository;
    public EventBet $eventBetsRepository;

    public function __construct(
        Discord $discord,
        $config,
        User $userRepository,
        Event $eventRepository,
        EventBet $eventBetsRepository
    )
    {
        $this->discord = $discord;
        $this->config = $config;
        $this->userRepository = $userRepository;
        $this->eventRepository = $eventRepository;
        $this->eventBetsRepository = $eventBetsRepository;
    }

    public function makeBet(Interaction $interaction)
    {
        $discordId = $interaction->member->user->id;
        $eventId = $interaction->data->options['entrar']->options['evento']->value;
        $choiceKey = $interaction->data->options['entrar']->options['opcao']->value;
        $coins = $interaction->data->options['entrar']->options['coins']->value;
        $event = $this->eventRepository->listEventById($eventId);

        if (!$discordId) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Aconteceu um erro com seu usuário, encha o saco do admin do bot!'), true);
            return;
        }

        if (!$this->userRepository->userExistByDiscordId($discordId)) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Você ainda não coleteu suas coins iniciais! Digita **/coins** e pegue suas coins! :coin::coin::coin: '), true);
            return;
        }

        if (empty($event)) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent(sprintf('O evento #% não existe! :crying_cat_face:', $eventId)), true);
            return;
        }

        if ($this->eventRepository->canBet($eventId)) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Evento fechado para apostas! :crying_cat_face: '), true);
            return;
        }

        if ($this->eventBetsRepository->alreadyBetted($discordId, $eventId)) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Você já apostou neste evento!'), true);
            return;
        }

        if ($coins <= 0) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Valor da aposta inválido'), true);
            return;
        }

        if (!$this->userRepository->hasAvailableCoins($discordId, $coins)) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Você não possui coins suficientes! :crying_cat_face:'), true);
            return;
        }

        if ($this->eventBetsRepository->create($discordId, $eventId, $choiceKey, $coins)) {
            /**
             * @var Embed $embed
             */
            $embed = $this->discord->factory(Embed::class);
            $embed
                ->setTitle(sprintf('%s #%s', $event[0]['event_name'], $event[0]['event_id']))
                ->setColor('#F5D920')
                ->setDescription(sprintf(
                    "Você apostou **%s** chorume coins na **opção %s**.\n\nBoa sorte manolo!",
                    $coins,
                    $choiceKey
                ))
                ->setImage($this->config['images']['place_bet']);
            $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($embed), true);
        }
    }
}
