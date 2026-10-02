<?php

class IndexController extends pm_Controller_Action
{
    protected $_accessLevel = 'admin';

    public function init()
    {
        parent::init();

        // Main administrator and additional administrators may use the extension.
        if (!pm_Session::getClient()->isAdmin()) {
            throw new pm_Exception($this->lmsg('errorAccessDenied'));
        }
        if (!pm_ProductInfo::isUnix()) {
            throw new pm_Exception($this->lmsg('errorLinuxOnly'));
        }

        $this->view->pageTitle = $this->lmsg('pageTitle');
    }

    public function indexAction()
    {
        $this->view->list = $this->_getList();
    }

    public function listDataAction()
    {
        $this->_helper->json($this->_getList()->fetchData());
    }

    public function inviteAction()
    {
        $this->view->pageTitle = $this->lmsg('invitePageTitle');
        $form = $this->_buildInviteForm();

        if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
            $email = trim($form->getValue('email'));
            try {
                $login = Modules_AdditionalAdministratorManager_Invitations::invite(
                    $email,
                    trim($form->getValue('contact')),
                    trim((string)$form->getValue('comment')),
                    (string)$form->getValue('locale')
                );
                $this->_status->addMessage('info', $this->lmsg('messageInvited', ['email' => $email, 'login' => $login]));
                $this->_helper->json(['redirect' => pm_Context::getBaseUrl()]);
            } catch (pm_Exception $e) {
                $form->markAsError();
                $form->getElement('email')->addError($e->getMessage());
            }
        }

        $this->view->form = $form;
        $this->render('form');
    }

    public function editAction()
    {
        if (!Modules_AdditionalAdministratorManager_Settings::allowUserEdit()) {
            throw new pm_Exception($this->lmsg('errorEditDisabled'));
        }

        $login = $this->_requireAccountParam();
        $this->view->pageTitle = $this->lmsg('editPageTitle', ['login' => $login]);
        $info = Modules_AdditionalAdministratorManager_AdminAlias::info($login);
        $info['locale'] = Modules_AdditionalAdministratorManager_Locales::ofAccount($login);
        // Invitation details are shown only while the account waits for the invitee to set it up.
        $invitationExpires = $info['enabled'] ? null : Modules_AdditionalAdministratorManager_Invitations::expiresAt($login);
        $form = $this->_buildEditForm($info, $invitationExpires);
        if ($invitationExpires !== null) {
            $this->view->resendUrl = pm_Context::getActionUrl('index', 'resend') . '?login=' . rawurlencode($login);
        }
        // Meter, generator, show/hide and copy for the password field (views/scripts/index/form.phtml).
        $this->view->passwordTools = [
            'policy' => Modules_AdditionalAdministratorManager_PasswordPolicy::policy(),
            'minimumLevel' => Modules_AdditionalAdministratorManager_PasswordPolicy::minimumLevel(),
        ];

        if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
            $email = trim((string)$form->getValue('email'));
            $owner = $email !== '' ? Modules_AdditionalAdministratorManager_AdminAlias::findLoginByEmail($email) : null;
            if ($owner !== null && $owner !== $login) {
                $form->markAsError();
                $form->getElement('email')->addError($this->lmsg('errorEmailInUse', ['email' => $email]));
            } elseif (strcasecmp($email, trim($info['email'])) !== 0
                && Modules_AdditionalAdministratorManager_AdminAlias::isPrimaryAdminEmail($email)) {
                // An account that already has this address can still be saved; only switching to it is refused.
                $form->markAsError();
                $form->getElement('email')->addError($this->lmsg('errorEmailPrimaryAdmin', ['email' => $email]));
            } elseif (!hash_equals((string)$form->getValue('password'), (string)$form->getValue('password_confirm'))) {
                $form->markAsError();
                $form->getElement('password_confirm')->addError($this->lmsg('errorPasswordMismatch'));
            } else {
                try {
                    $password = (string)$form->getValue('password');
                    $contact = trim((string)$form->getValue('contact'));
                    $comment = trim((string)$form->getValue('comment'));
                    // Only a changed language is written, so an account without one keeps following the server default.
                    $locale = (string)$form->getValue('locale');
                    $locale = $locale !== $info['locale'] ? $locale : null;
                    Modules_AdditionalAdministratorManager_AdminAlias::update($login, $password, $email, $contact, $comment, $locale);
                    if ($invitationExpires !== null && $email !== '' && strcasecmp($email, $info['email']) !== 0) {
                        Modules_AdditionalAdministratorManager_Invitations::changeEmail($login, $email);
                    }
                    $this->_logEdit($info, $password, $email, $contact, $comment, $locale);
                    $this->_status->addMessage('info', $this->lmsg('messageUpdated', ['login' => $login]));
                    $this->_helper->json(['redirect' => pm_Context::getBaseUrl()]);
                } catch (pm_Exception $e) {
                    $form->markAsError();
                    $form->getElement('password')->addError($e->getMessage());
                }
            }
        }

        $this->view->form = $form;
        $this->render('form');
    }

    /**
     * Resends the invitation of one account (the link on the edit page) and returns to the edit page.
     */
    public function resendAction()
    {
        $this->_requirePost();
        $login = $this->_requireAccountParam();

        try {
            Modules_AdditionalAdministratorManager_Invitations::resend($login);
            $this->_status->addMessage('info', $this->lmsg('messageResent', ['login' => $login]));
        } catch (pm_Exception $e) {
            $this->_status->addMessage('error', $e->getMessage());
        }
        $this->_helper->json(['redirect' => self::_editUrl($login)]);
    }

    public function removeAction()
    {
        $this->_groupOperation('messageRemoved', function ($login) {
            Modules_AdditionalAdministratorManager_AdminAlias::remove($login);
            Modules_AdditionalAdministratorManager_Invitations::forget($login);
        });
    }

    public function suspendAction()
    {
        $this->_groupOperation('messageSuspended', function ($login) {
            Modules_AdditionalAdministratorManager_AdminAlias::setEnabled($login, false);
        });
    }

    public function activateAction()
    {
        $this->_groupOperation('messageActivated', function ($login) {
            // An invited account is enabled only when the invitee completes the setup page.
            if (Modules_AdditionalAdministratorManager_Invitations::isPending($login)) {
                throw new pm_Exception($this->lmsg('errorActivatePending'));
            }
            Modules_AdditionalAdministratorManager_AdminAlias::setEnabled($login, true);
        });
    }

    /**
     * Rejects anything but POST for actions that change accounts.
     */
    private function _requirePost()
    {
        if (!$this->getRequest()->isPost()) {
            throw new pm_Exception($this->lmsg('errorAccessDenied'));
        }
    }

    /**
     * @return string The ?login= parameter, which must name an existing additional administrator.
     */
    private function _requireAccountParam()
    {
        $login = (string)$this->_getParam('login', '');
        if ($login === '' || !Modules_AdditionalAdministratorManager_AdminAlias::exists($login)) {
            throw new pm_Exception($this->lmsg('errorNotFound', ['login' => $login]));
        }
        return $login;
    }

    private static function _editUrl($login)
    {
        return pm_Context::getActionUrl('index', 'edit') . '?login=' . rawurlencode($login);
    }

    /**
     * Logs an edit with the old and new values of the fields that changed. Blank password, email
     * and contact values and a null locale leave the current value unchanged (see AdminAlias::update()).
     */
    private function _logEdit(array $info, $password, $email, $contact, $comment, $locale)
    {
        // Plesk drops fields whose old and new values are equal, so the account is a new value only.
        $old = [];
        $new = ['logFieldAccount' => $info['login']];
        if ($contact !== '' && $contact !== $info['contact']) {
            $old['logFieldContact'] = $info['contact'];
            $new['logFieldContact'] = $contact;
        }
        if ($comment !== $info['comment']) {
            $old['logFieldComment'] = $info['comment'];
            $new['logFieldComment'] = $comment;
        }
        if ($locale !== null) {
            $old['logFieldLanguage'] = $info['locale'];
            $new['logFieldLanguage'] = $locale;
        }
        if ($email !== '' && strcasecmp($email, $info['email']) !== 0) {
            $old['logFieldEmail'] = '';
            $new['logFieldEmail'] = $this->lmsg('logValueChanged');
        }
        if ($password !== '') {
            $old['logFieldPassword'] = '';
            $new['logFieldPassword'] = $this->lmsg('logValueChanged');
        }
        Modules_AdditionalAdministratorManager_Log::submit(Modules_AdditionalAdministratorManager_Log::ACCOUNT_EDITED, $old, $new);
    }

    /**
     * Applies $operation to each selected login and responds with per-login status messages.
     */
    private function _groupOperation($successMessageKey, callable $operation)
    {
        $this->_requirePost();

        $existing = Modules_AdditionalAdministratorManager_AdminAlias::listLogins();
        $messages = [];
        foreach ((array)$this->_getParam('ids', []) as $login) {
            $login = (string)$login;
            if (!in_array($login, $existing, true)) {
                $messages[] = ['status' => 'error', 'content' => $this->lmsg('errorNotFound', ['login' => $login])];
                continue;
            }
            try {
                $operation($login);
                $messages[] = ['status' => 'info', 'content' => $this->lmsg($successMessageKey, ['login' => $login])];
            } catch (pm_Exception $e) {
                $messages[] = ['status' => 'error', 'content' => $login . ': ' . $e->getMessage()];
            }
        }

        $this->_helper->json(['status' => 'success', 'statusMessages' => $messages]);
    }

    private function _getList()
    {
        $accounts = Modules_AdditionalAdministratorManager_AdminAlias::all();
        // Safety net for account changes Plesk did not report: delete orphaned invitations, follow renames.
        Modules_AdditionalAdministratorManager_Invitations::sweep($accounts);

        $data = [];
        foreach ($accounts as $login => $info) {
            $data[] = [
                'id' => $login,
                'contact' => $this->_contactCell($info, $accounts),
                'login' => $info['login'],
                'email' => $info['email'],
                'comment' => $info['comment'],
            ];
        }

        $list = new pm_View_List_Simple($this->view, $this->_request);
        $list->setData($data);
        $list->setColumns([
            pm_View_List_Simple::COLUMN_SELECTION,
            'contact' => [
                'title' => $this->lmsg('columnContact'),
                'noEscape' => true,
                'searchable' => true,
                'sortable' => true,
            ],
            'login' => [
                'title' => $this->lmsg('columnLogin'),
                'searchable' => true,
                'sortable' => true,
            ],
            'email' => [
                'title' => $this->lmsg('columnEmail'),
                'searchable' => true,
                'sortable' => true,
            ],
            'comment' => [
                'title' => $this->lmsg('columnComment'),
                'sortable' => false,
            ],
        ]);
        $list->setTools([
            [
                'title' => $this->lmsg('buttonInvite'),
                'description' => $this->lmsg('buttonInviteDescription'),
                'class' => 'sb-add-new btn-primary',
                'link' => pm_Context::getActionUrl('index', 'invite'),
            ],
            self::_separator(),
            [
                'title' => $this->lmsg('buttonRemove'),
                'description' => $this->lmsg('buttonRemoveDescription'),
                'class' => 'sb-remove-selected',
                'execGroupOperation' => [
                    'url' => pm_Context::getActionUrl('index', 'remove'),
                    'skipConfirmation' => false,
                    'subtype' => 'delete',
                    'text' => $this->lmsg('confirmRemove'),
                ],
            ],
            self::_separator(),
            [
                // Plesk's "More" pull-down. The SDK passes nested operations through as-is,
                // so their click handlers are attached in views/scripts/index/index.phtml.
                'componentType' => 'Jsw.list.AdditionalActions',
                'cls' => 'actions-menu',
                'title' => $this->lmsg('buttonMore'),
                'operations' => [
                    [
                        'componentType' => 'Jsw.SmallButton',
                        'id' => 'buttonActivateAdminAlias',
                        'cls' => 'sb-btn',
                        'addCls' => 'sb-activate',
                        'href' => '#',
                        'title' => $this->lmsg('buttonActivate'),
                        'description' => $this->lmsg('buttonActivateDescription'),
                    ],
                    [
                        'componentType' => 'Jsw.SmallButton',
                        'id' => 'buttonInactiveAdminAlias',
                        'cls' => 'sb-btn',
                        'addCls' => 'sb-suspend',
                        'href' => '#',
                        'title' => $this->lmsg('buttonSuspend'),
                        'description' => $this->lmsg('buttonSuspendDescription'),
                    ],
                ],
            ],
        ]);
        $list->setDataUrl(['action' => 'list-data']);

        return $list;
    }

    /**
     * HTML of the contact column: the contact name, linked to the edit page when editing is allowed.
     * A disabled account gets Plesk's "off" icon with a tooltip saying why it is disabled.
     *
     * @param array<string, array> $accounts All accounts, passed on to the invitation checks
     */
    private function _contactCell(array $info, array $accounts)
    {
        $login = $info['login'];
        $cell = $this->view->escape($info['contact']);
        if (Modules_AdditionalAdministratorManager_Settings::allowUserEdit()) {
            $cell = '<a href="' . $this->view->escape(self::_editUrl($login)) . '">' . $cell . '</a>';
        }
        if ($info['enabled']) {
            return $cell;
        }

        if (Modules_AdditionalAdministratorManager_Invitations::isExpired($login, $accounts)) {
            $status = $this->lmsg('statusInvitationExpired');
        } elseif (Modules_AdditionalAdministratorManager_Invitations::isPending($login, $accounts)) {
            $status = $this->lmsg('statusInvitationPending');
        } else {
            $status = $this->lmsg('statusSuspended');
        }
        // Same markup as the status icons in Plesk's own lists.
        return '<div class="b-indent"><span class="b-indent-icon">'
            . '<span class="tooltipData">' . $this->view->escape($status) . '</span>'
            . '<img src="/cp/theme/icons/16/plesk/off.png" alt="off" title="">'
            . '</span> ' . $cell . '</div>';
    }

    /**
     * Toolbar spacer rendered as <span class="separator"><span></span></span>.
     */
    private static function _separator()
    {
        return ['componentType' => 'Jsw.bar.Separator', 'cls' => 'separator'];
    }

    private function _buildInviteForm()
    {
        $form = new pm_Form_Simple();

        $form->addElement('text', 'contact', [
            'label' => $this->lmsg('fieldContact'),
            'class' => 'f-middle-size',
            'required' => true,
            'filters' => ['StringTrim'],
        ]);
        $form->addElement('text', 'email', [
            'label' => $this->lmsg('fieldEmail'),
            'class' => 'f-middle-size',
            'required' => true,
            'filters' => ['StringTrim'],
            'validators' => [['EmailAddress', true]],
        ]);
        $this->_addLanguageElement($form, Modules_AdditionalAdministratorManager_Locales::defaultLocale());
        $form->addElement('text', 'comment', [
            'label' => $this->lmsg('fieldComment'),
            'class' => 'f-middle-size',
        ]);

        $form->addControlButtons([
            'sendTitle' => $this->lmsg('buttonSendInvitation'),
            'cancelLink' => pm_Context::getBaseUrl(),
        ]);

        return $form;
    }

    /**
     * Interface language of the account, listing the languages available in Plesk. The invitation
     * email and setup page use it too when the extension has a translation (see Locales).
     */
    private function _addLanguageElement(pm_Form_Simple $form, $value)
    {
        $form->addElement('select', 'locale', [
            'label' => $this->lmsg('fieldLanguage'),
            'description' => $this->lmsg('fieldLanguageHint'),
            'multiOptions' => Modules_AdditionalAdministratorManager_Locales::available(),
            'value' => $value,
            'required' => true,
        ]);
    }

    /**
     * @param int|null $invitationExpires Expiry time of the pending invitation, or null to hide the invitation row.
     */
    private function _buildEditForm(array $values, $invitationExpires = null)
    {
        $form = new pm_Form_Simple();

        $form->addElement('simpleText', 'login', [
            'label' => $this->lmsg('fieldLogin'),
            'value' => $values['login'],
            'escape' => true,
        ]);
        $form->addElement('text', 'contact', [
            'label' => $this->lmsg('fieldContact'),
            'class' => 'f-middle-size',
            'value' => $values['contact'],
            'filters' => ['StringTrim'],
            'required' => true,
        ]);
        $form->addElement('text', 'email', [
            'label' => $this->lmsg('fieldEmail'),
            'class' => 'f-middle-size',
            'value' => $values['email'],
            'filters' => ['StringTrim'],
            'validators' => [['EmailAddress', true]],
            'required' => true,
        ]);
        $form->addElement('password', 'password', [
            'label' => $this->lmsg('fieldPassword'),
            'description' => $this->lmsg('fieldPasswordEditHint'),
        ]);
        // The password element ignores the 'class' option, so set it (with Plesk's default class) as an attribute.
        $form->getElement('password')->setAttrib('class', 'input-text');
        $form->getElement('password')->setAttrib('autocomplete', 'new-password');
        $form->addElement('password', 'password_confirm', [
            'label' => $this->lmsg('fieldPasswordConfirm'),
        ]);
        $form->getElement('password_confirm')->setAttrib('class', 'input-text');
        $form->getElement('password_confirm')->setAttrib('autocomplete', 'new-password');
        $this->_addLanguageElement($form, $values['locale']);
        $form->addElement('text', 'comment', [
            'label' => $this->lmsg('fieldComment'),
            'value' => $values['comment'],
            'class' => 'f-middle-size',
        ]);
        if ($invitationExpires !== null) {
            $date = Modules_AdditionalAdministratorManager_Invitations::formatDate($invitationExpires);
            $status = $this->lmsg($invitationExpires < time() ? 'invitationExpiredOn' : 'invitationExpiresOn', ['date' => $date]);
            // The click handler in views/scripts/index/form.phtml posts the resend request.
            $form->addElement('simpleText', 'invitation', [
                'label' => $this->lmsg('fieldInvitation'),
                'value' => $this->view->escape($status)
                    . ' <a href="#" id="aam-resend-invitation">' . $this->view->escape($this->lmsg('linkResendInvitation')) . '</a>',
                'escape' => false,
            ]);
        }
        $form->addControlButtons(['cancelLink' => pm_Context::getBaseUrl()]);

        return $form;
    }
}
