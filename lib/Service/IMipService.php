<?php

declare(strict_types=1);

/**
 * Mail App
 *
 * @copyright 2022 Anna Larch <anna.larch@gmx.net>
 *
 * @author Anna Larch <anna.larch@gmx.net>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Mail\Service;

use OCA\Mail\Account;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Db\MailboxMapper;
use OCA\Mail\Db\Message;
use OCA\Mail\Db\MessageMapper;
use OCA\Mail\Exception\ServiceException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Calendar\IManager;
use Psr\Log\LoggerInterface;

class IMipService {
	private MessageMapper $messageMapper;
	private MailboxMapper $mailboxMapper;
	private AccountService $accountService;
	private MailManager $mailManager;
	private IManager $calendarManager;
	private LoggerInterface $logger;

	public function __construct(
		MessageMapper $messageMapper,
		MailboxMapper $mailboxMapper,
		AccountService $accountService,
		MailManager $mailManager,
		IManager $manager,
		LoggerInterface $logger,
	) {
		$this->messageMapper = $messageMapper;
		$this->mailboxMapper = $mailboxMapper;
		$this->accountService = $accountService;
		$this->mailManager = $mailManager;
		$this->calendarManager = $manager;
		$this->logger = $logger;
	}

	public function process() {
		$messages = $this->messageMapper->findIMipMessages();

		// Collect all mailboxes in memory
		$mailboxIds = array_unique(array_map(function (Message $message) {
			return $message->getMailboxId();
		}, $messages));

		$mailboxes = array_combine($mailboxIds, array_map(function (int $mailboxId) {
			try {
				return $this->mailboxMapper->findById($mailboxId);
			} catch (DoesNotExistException $e) {
				return null;
			}
		}, $mailboxIds));

		// Collect all accounts in memory
		$accountIds = array_unique(array_map(function (Mailbox $mailbox) {
			return $mailbox->getAccountId();
		}, $mailboxes));

		$accounts = array_combine($accountIds, array_map(function (int $accountId) {
			try {
				return $this->accountService->findById($accountId);
			} catch (DoesNotExistException $e) {
				return null;
			}
		}, $accountIds));

		// build the updated messages on a per-account basis, so we can bulk update for each account
		$processedMessages = [];
		foreach ($messages as $message) {
			/** @var Mailbox $mailbox */
			$mailbox = $mailboxes[$message->getMailboxId()];
			/** @var Account $account */
			$account = $accounts[$mailbox->getAccountId()];
			// mailbox not in collated array, maybe specal use?
			// no processing for drafts and sent items
			if ($mailbox->isSpecialUse("sent") || $mailbox->isSpecialUse("drafts")) { // does this need more use cases? Also probably won't work? @todo
				$message->setImipProcessed(true); // Silently drop from passing to DAV and mark as processed, so we won't run into this message again.
				$processedMessages[$account->getId()][] = $message;
				continue;
			}


			try {
				$imapMessage = $this->mailManager->getImapMessage($account, $mailbox, $message->getUid());
			} catch (ServiceException $e) {
				$message->setImipError(true);
				$processedMessages[$account->getId()][] = $message;
				continue;
			}

			if (empty($imapMessage->scheduling)) {
				// No scheduling info, maybe the DB is wrong
				$message->setImipError(true);
				$processedMessages[$account->getId()][] = $message;
				continue;
			}

			$principalUri = '';
			$sender = $imapMessage->getFrom()->first()->getEmail();
			$recipient = $account->getEmail();
			$processed = false;
			if ($imapMessage->scheduling['method'] === 'REPLY') {
				$processed = $this->calendarManager->handleIMipReply($principalUri, $sender, $recipient, $imapMessage->scheduling['content']);
			} elseif ($imapMessage->scheduling['method'] === 'CANCEL') {
				$processed = $this->calendarManager->handleIMipCancel($principalUri, $sender, $recipient, $imapMessage->scheduling['content']);
			}
			$message->setImipProcessed($processed);
			$message->setImipError(!$processed);
			$processedMessages[$account->getId()][] = $message;
		}

		foreach ($accountIds as $accountId) {
			if (!isset($processedMessages[$accountId])) {
				continue;
			}
			try {
				$this->messageMapper->updateBulk($accounts[$accountId], false, $processedMessages[$accountId]);
			} catch (\Throwable $e) {
				$this->logger->error('Could not update iMip messages for account ' . $accountId, ['exception' => $e]);
			}
		}
	}
}
