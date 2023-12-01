<?php

namespace Chorume\Application\Commands;

use Discord\Discord;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Embed\Embed;
use Chorume\Repository\User;
use Chorume\Repository\UserCoinHistory;

class GenericCommand
{
    public $discord;
    public $config;
    public User $userRepository;
    public UserCoinHistory $userCoinHistoryRepository;

    public function __construct(
        Discord $discord,
        $config,
        User $userRepository,
        UserCoinHistory $userCoinHistoryRepository
    )
    {
        $this->discord = $discord;
        $this->config = $config;
        $this->userRepository = $userRepository;
        $this->userCoinHistoryRepository = $userCoinHistoryRepository;
    }

    public function coins(Interaction $interaction)
    {
        $discordId = $interaction->member->user->id;
        $user = $this->userRepository->getByDiscordId($discordId);
        $message = '';

        if (!$discordId) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Aconteceu um erro com seu usuário, encha o saco do admin do bot!'), true);
            return;
        }

        if (empty($user)) {
            if ($this->userRepository->giveInitialCoins(
                $interaction->member->user->id,
                $interaction->member->user->username
            )) {
                $interaction->respondWithMessage(MessageBuilder::new()->setContent(sprintf(
                    'Você acabou de receber suas **%s** coins iniciais! Aposte com sabedoria :man_mage:',
                    100
                )), true);
            }
        }

        $coinsQuery = $this->userRepository->getCurrentCoins($interaction->member->user->id);
        $currentCoins = $coinsQuery[0]['total'];
        $dailyCoins = 100;

        if ($this->userRepository->canReceivedDailyCoins($interaction->member->user->id) && !empty($user)) {
            $currentCoins += $dailyCoins;
            $this->userRepository->giveDailyCoins($interaction->member->user->id, $dailyCoins);

            $message .= "Você recebeu suas **%s** coins diárias! :money_mouth:\n\n";
            $message = sprintf($message, $dailyCoins);
        }

        /**
         * @var Embed $embed
         */
        $embed = $this->discord->factory(Embed::class);
        $embed
            ->setTitle('EXTRATO DE COINS')
            ->setColor('#F5D920');

        if ($currentCoins <= 0) {
            $message .= sprintf('Você não possui nenhuma coin, seu liso! :money_with_wings:', $currentCoins);
            $image = $this->config['images']['nomoney'];
        } elseif ($currentCoins > 1000) {
            $message .= sprintf('Você possui **%s** coins!! Tá faturando hein! :moneybag: :partying_face:', $currentCoins);
            $image = $this->config['images']['many_coins'];
        } else {
            $message .= sprintf('Você possui **%s** coins! :coin:', $currentCoins);
            $image = $this->config['images']['one_coin'];
        }

        $embed
            ->setDescription($message)
            ->setImage($image);

        $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($embed), true);
    }

    public function topBetters(Interaction $interaction)
    {
        $top10list = $this->userCoinHistoryRepository->listTop10();
        $topBettersImage = $this->config['images']['top_betters'];

        /**
         * @var Embed $embed
         */
        $embed = $this->discord->factory(Embed::class);
        $embed
            ->setTitle(sprintf('TOP 10 APOSTADORES'))
            ->setColor('#F5D920')
            ->setDescription('')
            ->setImage($topBettersImage);

        $users = '';
        $acc = '';

        foreach ($top10list as $bet) {
            $users .= sprintf("<@%s> \n", $bet['discord_user_id']);
            $acc .= sprintf("%s \n", $bet['total_coins']);
        }

        $embed
            ->addField([ 'name' => 'Usuário', 'value' => $users, 'inline' => 'true' ])
            ->addField([ 'name' => 'Acumulado', 'value' => $acc, 'inline' => 'true' ]);

        $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($embed));
    }

    public function transfer(Interaction $interaction)
    {
        $fromDiscordId = $interaction->member->user->id;
        $coins = $interaction->data->options['coins']->value;
        $toDiscordId = $interaction->data->options['usuario']->value;
        $fromUser = $this->userRepository->getByDiscordId($fromDiscordId);
        $toUser = $this->userRepository->getByDiscordId($toDiscordId);
        $embed = $this->discord->factory(Embed::class);

        $daysActiveAccount = (new \DateTime())->diff(new \DateTime($fromUser[0]['created_at']))->days;

        if ($coins <= 0 || $coins > 1000) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Quantidade inválida. Valor deve ser entre 1 e 1000 coins'), true);
            return;
        }

        if ($daysActiveAccount <= 15) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Sua conta no Chorume Coins precisa ter mais de 15 dias para transferir coins'), true);
            return;
        }

        if (!$this->userRepository->hasAvailableCoins($fromDiscordId, $coins)) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Você não possui saldo suficiente'), true);
            return;
        }

        if ($fromDiscordId === $toDiscordId) {
            $this->userCoinHistoryRepository->create($fromUser[0]['id'], -$coins, 'Troll');

            $embed
                ->setTitle('TROLL')
                ->setColor('#44f520');

            $message = sprintf("Nossa mas você é engraçado mesmo né. Por ter sido troll por transferir para você mesmo, acabou de perder **%s** coins pela zoeira!\n\nInclusive tá todo mundo vendo essa merda aí que tu ta fazendo!\n\nHA! HA! HA! ENGRAÇADÃO! 👹👹👹", -$coins);
            $image = $this->config['images']['sefodeu'];

            $embed
                ->setDescription($message)
                ->setImage($image);

            $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($embed), false);
            return;
        }

        if (!$this->userRepository->userExistByDiscordId($fromDiscordId)) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Remetente não encontrado'), true);
            return;
        }

        if (!$this->userRepository->userExistByDiscordId($toDiscordId)) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Beneficiário não encontrado'), true);
            return;
        }

        if (!$this->userCoinHistoryRepository->transfer($fromUser[0]['id'], $coins, $toUser[0]['id'])) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Erro inesperado ao transferir'), true);
            return;
        }

        $interaction->respondWithMessage(MessageBuilder::new()->setContent(sprintf("<@%s> transferiu **%s** coins para <@%s>! :money_mouth: :money_mouth: :money_mouth:", $fromDiscordId, $coins, $toDiscordId)), false);
    }
}
