<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\{A, BR, INPUT, LABEL, OPTION, P, SELECT, SMALL, TABLE, TBODY, TD, TFOOT, TH, TR, emptyHTML, joinHTML};

use MicroHTML\HTMLElement;

class UserPageTheme extends Themelet
{
    public function display_login_page(): void
    {
        Ctx::$page->set_title("Login");
        $this->display_navigation();
        Ctx::$page->add_block(new Block(
            "Login There",
            emptyHTML("There should be a login box to the left")
        ));
    }

    /**
     * @param array<int, array{name: string|HTMLElement, link: Url}> $parts
     */
    public function display_user_links(User $user, array $parts): void
    {
        # $page->add_block(new Block("User Links", join(", ", $parts), "main", 10));
    }

    /**
     * @param array<array{name: string|HTMLElement, link: Url}> $parts
     */
    public function display_user_block(User $user, array $parts): void
    {
        $html = emptyHTML('Logged in as ', $user->name);
        foreach ($parts as $part) {
            $html->appendChild(BR());
            $html->appendChild(A(["href" => (string)$part["link"]], $part["name"]));
        }
        Ctx::$page->add_block(new Block("User Links", $html, "left", 90, is_content: false));
    }

    public function display_signup_page(): void
    {
        $tac = Ctx::$config->get(UserAccountsConfig::LOGIN_TAC) ?? "";

        if (Ctx::$config->get(UserAccountsConfig::LOGIN_TAC_BBCODE)) {
            $tac = format_text($tac);
        }

        $email_required = (
            Ctx::$config->get(UserAccountsConfig::USER_EMAIL_REQUIRED) &&
            !Ctx::$user->can(UserAccountsPermission::CREATE_OTHER_USER)
        );
        $captcha = Captcha::get_html(UserAccountsPermission::SKIP_SIGNUP_CAPTCHA);

        $form = SHM_SIMPLE_FORM(
            make_link("user_admin/create"),
            TABLE(
                ["class" => "form"],
                TBODY(
                    TR(
                        TH("Name"),
                        TD(INPUT(["type" => 'text', "name" => 'name', "required" => true]))
                    ),
                    TR(
                        TH("Password"),
                        TD(INPUT(["type" => 'password', "name" => 'pass1', "required" => true]))
                    ),
                    TR(
                        TH(\MicroHTML\rawHTML("Repeat&nbsp;Password")),
                        TD(INPUT(["type" => 'password', "name" => 'pass2', "required" => true]))
                    ),
                    TR(
                        TH($email_required ? "Email" : \MicroHTML\rawHTML("Email&nbsp;(Optional)")),
                        TD(INPUT(["type" => 'email', "name" => 'email', "required" => $email_required]))
                    ),
                    $captcha ? TR(
                        TD(["colspan" => "2"], $captcha)
                    ) : null,
                ),
                TFOOT(
                    TR(TD(["colspan" => "2"], INPUT(["type" => "submit", "value" => "Create Account"])))
                )
            )
        );

        $html = emptyHTML(
            $tac ? P($tac) : null,
            $form
        );

        Ctx::$page->set_title("Create Account");
        $this->display_navigation();
        Ctx::$page->add_block(new Block("Signup", $html));
    }

    public function display_user_creator(): void
    {
        $form = SHM_SIMPLE_FORM(
            make_link("user_admin/create_other"),
            TABLE(
                ["class" => "form"],
                TBODY(
                    TR(
                        TH("Name"),
                        TD(INPUT(["type" => 'text', "name" => 'name', "required" => true]))
                    ),
                    TR(
                        TH("Password"),
                        TD(INPUT(["type" => 'password', "name" => 'pass1', "required" => true]))
                    ),
                    TR(
                        TH(\MicroHTML\rawHTML("Repeat&nbsp;Password")),
                        TD(INPUT(["type" => 'password', "name" => 'pass2', "required" => true]))
                    ),
                    TR(
                        TH("Email"),
                        TD(INPUT(["type" => 'email', "name" => 'email']))
                    ),
                    TR(
                        TD(["colspan" => 2], "(Email is optional for admin-created accounts)"),
                    ),
                ),
                TFOOT(
                    TR(TD(["colspan" => "2"], INPUT(["type" => "submit", "value" => "Create Account"])))
                )
            )
        );
        Ctx::$page->add_block(new Block("Create User", $form, "main", 75));
    }

    public function display_signups_disabled(): void
    {
        Ctx::$page->set_title("Signups Disabled");
        $this->display_navigation();
        Ctx::$page->add_block(new Block(
            "Signups Disabled",
            format_text(Ctx::$config->get(UserAccountsConfig::SIGNUP_DISABLED_MESSAGE)),
        ));
    }

    public function display_login_block(): void
    {
        Ctx::$page->add_block(new Block("Login", $this->create_login_block(), "left", 90));
    }

    public function create_login_block(): HTMLElement
    {
        $captcha = Captcha::get_html(UserAccountsPermission::SKIP_LOGIN_CAPTCHA);

        $form = SHM_SIMPLE_FORM(
            make_link("user_admin/login"),
            TABLE(
                ["style" => "width: 100%", "class" => "form"],
                TBODY(
                    TR(
                        TH(LABEL(["for" => "user"], "Name")),
                        TD(INPUT(["id" => "user", "type" => "text", "name" => "user", "autocomplete" => "username", "required" => true]))
                    ),
                    TR(
                        TH(LABEL(["for" => "pass"], "Password")),
                        TD(INPUT(["id" => "pass", "type" => "password", "name" => "pass", "autocomplete" => "current-password", "required" => true]))
                    ),
                    $captcha ? TR(
                        TH(LABEL(["for" => "captcha"], "Captcha")),
                        TD($captcha)
                    ) : null
                ),
                TFOOT(
                    TR(TD(["colspan" => "2"], INPUT(["type" => "submit", "value" => "Log In"])))
                )
            )
        );

        $html = emptyHTML();
        $html->appendChild($form);
        if (Ctx::$config->get(UserAccountsConfig::SIGNUP_ENABLED) && Ctx::$user->can(UserAccountsPermission::CREATE_USER)) {
            $html->appendChild(SMALL(A(["href" => make_link("user_admin/create")], "Create Account")));
        }

        return $html;
    }

    /**
     * @param array<string, int> $ips
     */
    protected function _ip_list(string $name, array $ips): HTMLElement
    {
        $td = TD("$name: ");
        $n = 0;
        foreach ($ips as $ip => $count) {
            $td->appendChild(BR());
            $td->appendChild("$ip ($count)");
            if (++$n >= 20) {
                $td->appendChild(BR());
                $td->appendChild("...");
                break;
            }
        }
        return $td;
    }

    /**
     * @param array<string, int> $uploads
     * @param array<string, int> $comments
     * @param array<string, int> $events
     */
    public function display_ip_list(array $uploads, array $comments, array $events): void
    {
        $html = TABLE(
            ["id" => "ip-history"],
            TR(
                $this->_ip_list("Uploaded from", $uploads),
                $this->_ip_list("Commented from", $comments),
                $this->_ip_list("Logged Events", $events)
            ),
            TR(
                TD(["colspan" => "3"], "(Most recent at top)")
            )
        );

        Ctx::$page->add_block(new Block("IPs", $html, "main", 70));
    }

    /**
     * @param array<HTMLElement|string> $stats
     */
    public function display_user_page(User $duser, array $stats): void
    {
        $stats[] = emptyHTML('User ID: '.$duser->id);

        Ctx::$page->set_title("{$duser->name}'s Page");
        $this->display_navigation();
        Ctx::$page->add_block(new Block("Stats", joinHTML(BR(), $stats), "main", 10));
    }


    public function build_operations(User $duser, UserOperationsBuildingEvent $event): HTMLElement
    {
        $html = emptyHTML();

        // just a fool-admin protection so they dont mess around with anon users.
        if ($duser->id !== Ctx::$config->get(UserAccountsConfig::ANON_ID)) {
            if (Ctx::$user->can(UserAccountsPermission::EDIT_USER_NAME)) {
                $html->appendChild(SHM_USER_FORM(
                    $duser,
                    make_link("user_admin/change_name"),
                    "Change Name",
                    TBODY(TR(
                        TH("New name"),
                        TD(INPUT(["type" => 'text', "name" => 'name', "value" => $duser->name]))
                    )),
                    "Set"
                ));
            }

            $html->appendChild(SHM_USER_FORM(
                $duser,
                make_link("user_admin/change_pass"),
                "Change Password",
                TBODY(
                    TR(
                        TH("Password"),
                        TD(INPUT(["type" => 'password', "name" => 'pass1', "autocomplete" => 'new-password']))
                    ),
                    TR(
                        TH("Repeat password"),
                        TD(INPUT(["type" => 'password', "name" => 'pass2', "autocomplete" => 'new-password']))
                    ),
                ),
                "Set"
            ));

            $html->appendChild(SHM_USER_FORM(
                $duser,
                make_link("user_admin/change_email"),
                "Change Email",
                TBODY(TR(
                    TH("Address"),
                    TD(INPUT(["type" => 'text', "name" => 'address', "value" => $duser->email, "autocomplete" => 'email', "inputmode" => 'email']))
                )),
                "Set"
            ));

            if (Ctx::$user->can(UserAccountsPermission::EDIT_USER_CLASS)) {
                $select = SELECT(["name" => "class"]);
                foreach (UserClass::$known_classes as $name => $values) {
                    $select->appendChild(
                        OPTION(["value" => $name, "selected" => $name === $duser->class->name], ucwords($name))
                    );
                }
                $html->appendChild(SHM_USER_FORM(
                    $duser,
                    make_link("user_admin/change_class"),
                    "Change Class",
                    TBODY(TR(TD($select))),
                    "Set"
                ));
            }

            if (Ctx::$user->can(UserAccountsPermission::DELETE_USER)) {
                $html->appendChild(SHM_USER_FORM(
                    $duser,
                    make_link("user_admin/delete_user"),
                    "Delete User",
                    TBODY(
                        TR(TD(LABEL(INPUT(["type" => 'checkbox', "name" => 'with_images']), "Delete images"))),
                        TR(TD(LABEL(INPUT(["type" => 'checkbox', "name" => 'with_comments']), "Delete comments"))),
                    ),
                    TFOOT(
                        TR(TD(INPUT(["type" => 'button', "class" => 'shm-unlocker', "data-unlock-sel" => '.deluser', "value" => 'Unlock']))),
                        TR(TD(INPUT(["type" => 'submit', "class" => 'deluser', "value" => 'Delete User', "disabled" => 'true']))),
                    )
                ));
            }

            foreach ($event->get_parts() as $part) {
                $html->appendChild($part);
            }
        }
        return $html;
    }

    public function get_help_html(): HTMLElement
    {
        return emptyHTML(
            P("Search for posts posted by particular individuals."),
            SHM_COMMAND_EXAMPLE("poster=username", 'Returns posts posted by "username"'),
            // SHM_COMMAND_EXAMPLE("poster_id=123", 'Returns posts posted by user 123'),
            Ctx::$user->can(IPBanPermission::VIEW_IP)
                ? SHM_COMMAND_EXAMPLE("poster_ip=127.0.0.1", "Returns posts posted from IP 127.0.0.1.")
                : null
        );
    }

    // ── Unified User Manager ─────────────────────────────────────────────────

    /**
     * @param array<array<string,mixed>> $users
     * @param array<array<string,mixed>> $bans
     * @param string[] $classes
     */
    public function display_user_manager(array $users, array $bans, array $classes): void
    {
        $token = Ctx::$user->get_auth_token();
        $me_id = Ctx::$user->id;

        $url_set_class = make_link('user_admin/mgr_set_class');
        $url_delete    = make_link('user_admin/mgr_delete');
        $url_unban     = make_link('user_admin/mgr_unban');
        $url_ban       = make_link('user_admin/mgr_ban');
        $url_create    = make_link('user_admin/mgr_create');

        $total_users = count($users);
        $admin_count = count(array_filter($users, fn($u) => $u['class'] === 'admin'));
        $ban_count   = count($bans);

        // ── User rows ─────────────────────────────────────────────────────────
        $user_rows = '';
        foreach ($users as $u) {
            $uid    = (int)$u['id'];
            $uname  = htmlspecialchars((string)$u['name'],    ENT_QUOTES, 'UTF-8');
            $uclass = htmlspecialchars((string)$u['class'],   ENT_QUOTES, 'UTF-8');
            $uemail = htmlspecialchars((string)($u['email'] ?? ''), ENT_QUOTES, 'UTF-8');
            $ujoin  = htmlspecialchars(substr((string)($u['joindate'] ?? ''), 0, 10), ENT_QUOTES, 'UTF-8');
            $uposts = (int)$u['post_count'];

            $badge_bg = match($uclass) {
                'admin' => '#EF4444',
                'user'  => '#6366F1',
                'ghost' => '#94A3B8',
                default => '#64748B',
            };

            $opts = '';
            foreach ($classes as $cls) {
                $sel  = $cls === $uclass ? ' selected' : '';
                $clsE = htmlspecialchars($cls, ENT_QUOTES, 'UTF-8');
                $opts .= "<option value=\"{$clsE}\"{$sel}>{$clsE}</option>";
            }

            $del_attr = $uid === $me_id
                ? ' disabled title="You cannot delete yourself"'
                : ' onclick="return confirm(\'Delete user ' . addslashes($uname) . '?\')"';

            // Ban / Unban button — only for regular users and ghosted users, not self/admin
            if ($uid !== $me_id && in_array($uclass, ['user', 'ghost'], true)) {
                if ($uclass === 'ghost') {
                    $ban_target = 'user';
                    $ban_label  = 'Unban';
                    $ban_extra  = 'um-btn--ok';
                } else {
                    $ban_target = 'ghost';
                    $ban_label  = 'Ban';
                    $ban_extra  = 'um-btn--warn';
                }
                $ban_confirm = $uclass === 'user'
                    ? ' onclick="return confirm(\'Ban user ' . addslashes($uname) . '?\')"'
                    : '';
                $ban_btn = "<form method=\"POST\" action=\"{$url_set_class}\" class=\"um-row-form\">"
                    . "<input type=\"hidden\" name=\"auth_token\" value=\"{$token}\">"
                    . "<input type=\"hidden\" name=\"id\" value=\"{$uid}\">"
                    . "<input type=\"hidden\" name=\"class\" value=\"{$ban_target}\">"
                    . "<button type=\"submit\" class=\"um-btn {$ban_extra}\"{$ban_confirm}>{$ban_label}</button>"
                    . "</form>";
            } else {
                $ban_btn = '';
            }

            $profile_link = make_link("user/{$uname}");

            $user_rows .= <<<ROW
<tr data-name="{$uname}" data-class="{$uclass}">
  <td class="um-td-id">{$uid}</td>
  <td><a href="{$profile_link}" class="um-user-link">{$uname}</a></td>
  <td><span class="um-badge" style="background:{$badge_bg}">{$uclass}</span></td>
  <td class="um-td-email">{$uemail}</td>
  <td class="um-td-date">{$ujoin}</td>
  <td class="um-td-num">{$uposts}</td>
  <td class="um-td-action">
    <form method="POST" action="{$url_set_class}" class="um-row-form">
      <input type="hidden" name="auth_token" value="{$token}">
      <input type="hidden" name="id" value="{$uid}">
      <select name="class" class="um-select">{$opts}</select>
      <button type="submit" class="um-btn um-btn--save">Save</button>
    </form>
  </td>
  <td class="um-td-action" style="display:flex;gap:.35rem;align-items:center">
    {$ban_btn}
    <form method="POST" action="{$url_delete}" class="um-row-form">
      <input type="hidden" name="auth_token" value="{$token}">
      <input type="hidden" name="id" value="{$uid}">
      <button type="submit" class="um-btn um-btn--danger"{$del_attr}>Delete</button>
    </form>
  </td>
</tr>
ROW;
        }

        // ── Ban rows ──────────────────────────────────────────────────────────
        $ban_rows = '';
        if (empty($bans)) {
            $ban_rows = '<tr><td colspan="7" class="um-empty">No active IP bans.</td></tr>';
        }
        foreach ($bans as $b) {
            $bid     = (int)$b['id'];
            $bip     = htmlspecialchars((string)$b['ip'],     ENT_QUOTES, 'UTF-8');
            $bmode   = htmlspecialchars((string)$b['mode'],   ENT_QUOTES, 'UTF-8');
            $breason = htmlspecialchars((string)$b['reason'], ENT_QUOTES, 'UTF-8');
            $badded  = htmlspecialchars(substr((string)($b['added'] ?? ''), 0, 10), ENT_QUOTES, 'UTF-8');
            $bexp    = $b['expires']
                ? htmlspecialchars(substr((string)$b['expires'], 0, 10), ENT_QUOTES, 'UTF-8')
                : '<span style="color:var(--mb-text-3,#94A3B8)">Never</span>';
            $bbanner = htmlspecialchars((string)$b['banner'], ENT_QUOTES, 'UTF-8');

            $mode_bg = match($bmode) {
                'block'      => '#EF4444',
                'firewall'   => '#F97316',
                'ghost'      => '#8B5CF6',
                'anon-ghost' => '#6366F1',
                default      => '#64748B',
            };

            $ban_rows .= <<<BANROW
<tr>
  <td style="font-family:monospace;font-size:.82rem">{$bip}</td>
  <td><span class="um-badge" style="background:{$mode_bg}">{$bmode}</span></td>
  <td class="um-td-reason">{$breason}</td>
  <td class="um-td-date">{$badded}</td>
  <td class="um-td-date">{$bexp}</td>
  <td class="um-td-date">{$bbanner}</td>
  <td class="um-td-action">
    <form method="POST" action="{$url_unban}" class="um-row-form">
      <input type="hidden" name="auth_token" value="{$token}">
      <input type="hidden" name="id" value="{$bid}">
      <button type="submit" class="um-btn um-btn--ghost"
              onclick="return confirm('Remove ban for {$bip}?')">Remove</button>
    </form>

  </td>
</tr>
BANROW;
        }

        // ── Class options for create form ─────────────────────────────────────
        $create_class_opts = '';
        foreach ($classes as $cls) {
            $sel  = $cls === 'user' ? ' selected' : '';
            $clsE = htmlspecialchars($cls, ENT_QUOTES, 'UTF-8');
            $create_class_opts .= "<option value=\"{$clsE}\"{$sel}>{$clsE}</option>";
        }

        $html = <<<HTML
<style>
#um-wrap { font-family:inherit; }
.um-stats { display:flex; gap:.75rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.um-stat  { flex:1; min-width:120px; background:var(--mb-card-bg,#F8FAFC);
            border:1px solid var(--mb-border,#E2E8F0); border-radius:12px;
            padding:.9rem 1.1rem; text-align:center; }
.um-stat__num { font-size:1.6rem; font-weight:800; color:var(--mb-accent,#6366F1); line-height:1; }
.um-stat__lbl { font-size:.72rem; color:var(--mb-text-2,#475569);
                text-transform:uppercase; letter-spacing:.05em; margin-top:.25rem; }
.um-tabs { display:flex; gap:0; border:1px solid var(--mb-border,#E2E8F0);
           border-radius:10px; overflow:hidden; margin-bottom:1rem; width:fit-content; }
.um-tab  { padding:.38rem 1.1rem; border:none; cursor:pointer; font-size:.82rem;
           font-weight:600; background:var(--mb-card-bg,#F8FAFC);
           color:var(--mb-text-2,#475569); transition:background .12s; }
.um-tab + .um-tab { border-left:1px solid var(--mb-border,#E2E8F0); }
.um-tab--active { background:var(--mb-accent,#6366F1); color:#fff; }
.um-panel { display:none; }
.um-panel--active { display:block; }
.um-card { background:var(--mb-card-bg,#F8FAFC); border:1px solid var(--mb-border,#E2E8F0);
           border-radius:12px; overflow:hidden; margin-bottom:.75rem; }
.um-table { width:100%; border-collapse:collapse; font-size:.83rem; }
.um-table th { background:var(--mb-border,#E2E8F0); padding:.55rem .75rem;
               text-align:left; font-size:.72rem; font-weight:700;
               text-transform:uppercase; letter-spacing:.05em;
               color:var(--mb-text-2,#475569); white-space:nowrap; }
.um-table td { padding:.5rem .75rem; border-bottom:1px solid var(--mb-border,#E2E8F0); vertical-align:middle; }
.um-table tr:last-child td { border-bottom:none; }
.um-table tr:hover td { background:rgba(99,102,241,.04); }
.um-search-wrap { padding:.75rem; border-bottom:1px solid var(--mb-border,#E2E8F0); }
.um-search { width:100%; padding:.42rem .7rem; border-radius:8px; font-size:.85rem;
             border:1px solid var(--mb-border,#E2E8F0); box-sizing:border-box;
             background:var(--mb-bg,#fff); color:inherit; }
.um-search:focus { outline:none; border-color:var(--mb-accent,#6366F1); }
.um-badge { display:inline-block; padding:.15rem .55rem; border-radius:99px;
            font-size:.68rem; font-weight:700; color:#fff; white-space:nowrap; }
.um-btn { padding:.28rem .65rem; border:none; border-radius:7px; cursor:pointer;
          font-size:.75rem; font-weight:600; transition:background .12s; white-space:nowrap; }
.um-btn--save   { background:var(--mb-accent,#6366F1); color:#fff; }
.um-btn--save:hover { background:var(--mb-accent-hover,#4F46E5); }
.um-btn--danger { background:#EF4444; color:#fff; }
.um-btn--danger:hover { background:#DC2626; }
.um-btn--danger:disabled { background:#CBD5E1; color:#94A3B8; cursor:not-allowed; }
.um-btn--ghost  { background:transparent; color:var(--mb-text-2,#475569);
                  border:1px solid var(--mb-border,#E2E8F0); }
.um-btn--ghost:hover { background:var(--mb-border,#E2E8F0); }
.um-btn--warn   { background:#F97316; color:#fff; }
.um-btn--warn:hover { background:#EA6C0A; }
.um-btn--ok     { background:#22C55E; color:#fff; }
.um-btn--ok:hover   { background:#16A34A; }
.um-btn--primary { background:var(--mb-accent,#6366F1); color:#fff;
                   padding:.45rem 1.2rem; font-size:.85rem; }
.um-btn--primary:hover { background:var(--mb-accent-hover,#4F46E5); }
.um-row-form { display:flex; align-items:center; gap:.35rem; }
.um-select { padding:.28rem .5rem; border-radius:7px; font-size:.75rem;
             border:1px solid var(--mb-border,#E2E8F0); background:var(--mb-bg,#fff); color:inherit; }
.um-select:focus { outline:none; border-color:var(--mb-accent,#6366F1); }
.um-td-id     { color:var(--mb-text-3,#94A3B8); font-size:.75rem; width:36px; }
.um-td-email  { color:var(--mb-text-2,#475569); font-size:.78rem;
                max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.um-td-date   { color:var(--mb-text-2,#475569); font-size:.75rem; white-space:nowrap; }
.um-td-num    { text-align:center; font-size:.78rem; }
.um-td-action { white-space:nowrap; }
.um-td-reason { font-size:.78rem; max-width:220px; }
.um-empty     { text-align:center; padding:2rem; color:var(--mb-text-3,#94A3B8); font-size:.85rem; }
.um-user-link { font-weight:600; color:var(--mb-accent,#6366F1); text-decoration:none; }
.um-user-link:hover { text-decoration:underline; }
.um-form { padding:1.25rem; }
.um-form-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr));
                gap:.75rem; margin-bottom:.75rem; }
.um-field { display:flex; flex-direction:column; gap:.25rem; }
.um-label { font-size:.72rem; font-weight:700; text-transform:uppercase;
            letter-spacing:.05em; color:var(--mb-text-2,#475569); }
.um-input { padding:.42rem .65rem; border-radius:8px; font-size:.85rem;
            border:1px solid var(--mb-border,#E2E8F0); background:var(--mb-bg,#fff);
            color:inherit; width:100%; box-sizing:border-box; }
.um-input:focus { outline:none; border-color:var(--mb-accent,#6366F1); }
.um-hint { font-size:.72rem; color:var(--mb-text-3,#94A3B8); margin-top:.25rem; }
</style>

<div id="um-wrap">
  <div class="um-stats">
    <div class="um-stat">
      <div class="um-stat__num">{$total_users}</div>
      <div class="um-stat__lbl">Total Users</div>
    </div>
    <div class="um-stat">
      <div class="um-stat__num" style="color:#EF4444">{$admin_count}</div>
      <div class="um-stat__lbl">Admins</div>
    </div>
    <div class="um-stat">
      <div class="um-stat__num" style="color:#F97316">{$ban_count}</div>
      <div class="um-stat__lbl">Active Bans</div>
    </div>
  </div>

  <div class="um-tabs">
    <button type="button" class="um-tab um-tab--active" data-panel="users">&#128100; Users</button>
    <button type="button" class="um-tab" data-panel="bans">&#128683; IP Bans</button>
    <button type="button" class="um-tab" data-panel="create">&#43; New User</button>
  </div>

  <!-- Users tab -->
  <div class="um-panel um-panel--active" id="um-panel-users">
    <div class="um-card">
      <div class="um-search-wrap">
        <input type="search" class="um-search" id="um-user-search"
               placeholder="Filter by username or role…" autocomplete="off">
      </div>
      <div style="overflow-x:auto">
        <table class="um-table" id="um-user-table">
          <thead>
            <tr>
              <th>#</th><th>Username</th><th>Role</th><th>Email</th>
              <th>Joined</th><th>Posts</th><th>Change Role</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>{$user_rows}</tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- IP Bans tab -->
  <div class="um-panel" id="um-panel-bans">
    <div class="um-card">
      <div style="overflow-x:auto">
        <table class="um-table">
          <thead>
            <tr>
              <th>IP Address</th><th>Mode</th><th>Reason</th>
              <th>Added</th><th>Expires</th><th>Banned by</th><th></th>
            </tr>
          </thead>
          <tbody>{$ban_rows}</tbody>
        </table>
      </div>
    </div>
    <div class="um-card">
      <div class="um-form">
        <div style="font-size:.82rem;font-weight:700;margin-bottom:.75rem;color:var(--mb-text-2,#475569)">
          &#43; Add IP Ban
        </div>
        <form method="POST" action="{$url_ban}">
          <input type="hidden" name="auth_token" value="{$token}">
          <div class="um-form-grid">
            <div class="um-field">
              <label class="um-label">IP Address</label>
              <input type="text" name="ip" class="um-input" placeholder="192.168.1.1"
                     required autocomplete="off" spellcheck="false">
            </div>
            <div class="um-field">
              <label class="um-label">Mode</label>
              <select name="mode" class="um-input">
                <option value="block">Block</option>
                <option value="firewall">Firewall</option>
                <option value="ghost">Ghost</option>
                <option value="anon-ghost">Anon Ghost</option>
              </select>
            </div>
            <div class="um-field">
              <label class="um-label">Reason</label>
              <input type="text" name="reason" class="um-input" placeholder="Spam, abuse…" required>
            </div>
            <div class="um-field">
              <label class="um-label">Expires</label>
              <input type="text" name="expires" class="um-input" placeholder="blank = never">
              <span class="um-hint">e.g. +1 week, +30 days, 2026-12-31</span>
            </div>
          </div>
          <button type="submit" class="um-btn um-btn--primary">&#128683; Add Ban</button>
        </form>
      </div>
    </div>
  </div>

  <!-- New User tab -->
  <div class="um-panel" id="um-panel-create">
    <div class="um-card">
      <div class="um-form">
        <form method="POST" action="{$url_create}">
          <input type="hidden" name="auth_token" value="{$token}">
          <div class="um-form-grid">
            <div class="um-field">
              <label class="um-label">Username</label>
              <input type="text" name="name" class="um-input" required
                     autocomplete="off" placeholder="johndoe">
              <span class="um-hint">Letters, numbers, dash, underscore</span>
            </div>
            <div class="um-field">
              <label class="um-label">Password</label>
              <input type="password" name="pass1" class="um-input" required autocomplete="new-password">
            </div>
            <div class="um-field">
              <label class="um-label">Confirm Password</label>
              <input type="password" name="pass2" class="um-input" required autocomplete="new-password">
            </div>
            <div class="um-field">
              <label class="um-label">Email <span class="um-hint" style="display:inline">(optional)</span></label>
              <input type="email" name="email" class="um-input" autocomplete="off">
            </div>
            <div class="um-field">
              <label class="um-label">Role</label>
              <select name="class" class="um-input">{$create_class_opts}</select>
            </div>
          </div>
          <button type="submit" class="um-btn um-btn--primary">&#43; Create User</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';
  document.querySelectorAll('.um-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      document.querySelectorAll('.um-tab').forEach(function (t) { t.classList.remove('um-tab--active'); });
      document.querySelectorAll('.um-panel').forEach(function (p) { p.classList.remove('um-panel--active'); });
      tab.classList.add('um-tab--active');
      var panel = document.getElementById('um-panel-' + tab.dataset.panel);
      if (panel) panel.classList.add('um-panel--active');
    });
  });
  var search = document.getElementById('um-user-search');
  if (search) {
    search.addEventListener('input', function () {
      var q = this.value.toLowerCase().trim();
      document.querySelectorAll('#um-user-table tbody tr').forEach(function (row) {
        var match = !q || (row.dataset.name || '').toLowerCase().includes(q)
                        || (row.dataset.class || '').toLowerCase().includes(q);
        row.style.display = match ? '' : 'none';
      });
    });
  }
})();
</script>
HTML;

        Ctx::$page->add_block(new Block(null, \MicroHTML\rawHTML($html), 'main', 10, is_content: true));
    }
}
