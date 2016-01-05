<?php
namespace DreamFactory\Core\Components;

use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Utility\ServiceHandler;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Models\EmailTemplate;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use Illuminate\Contracts\Auth\Registrar as RegistrarContract;
use DreamFactory\Core\Services\Email\BaseService as EmailService;
use Validator;

class Registrar implements RegistrarContract
{
    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array $data
     *
     * @return \Illuminate\Contracts\Validation\Validator
     */
    public function validator(array $data)
    {
        $userService = Service::getCachedByName('user');
        $validationRules = [
            'name'       => 'required|max:255',
            'first_name' => 'required',
            'last_name'  => 'required',
            'email'      => 'required|email|max:255|unique:user'
        ];

        if (empty($userService['config']['open_reg_email_service_id'])) {
            $validationRules['password'] = 'required|confirmed|min:6';
        }

        return Validator::make($data, $validationRules);
    }

    /**
     * Creates a non-admin user.
     *
     * @param array $data
     *
     * @return \DreamFactory\Core\Models\User
     * @throws \DreamFactory\Core\Exceptions\ForbiddenException
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     * @throws \Exception
     */
    public function create(array $data)
    {
        $userService = Service::getCachedByName('user');
        if (!$userService['config']['allow_open_registration']) {
            throw new ForbiddenException('Open Registration is not enabled.');
        }

        $openRegEmailSvcId = $userService['config']['open_reg_email_service_id'];
        $openRegEmailTplId = $userService['config']['open_reg_email_template_id'];
        $openRegRoleId = $userService['config']['open_reg_role_id'];
        /** @type User $user */
        $user = User::create($data);

        if (!empty($openRegEmailSvcId)) {
            $this->sendConfirmation($user, $openRegEmailSvcId, $openRegEmailTplId);
        } else if (!empty($data['password'])) {
            $user->password = $data['password'];
            $user->save();
        }

        if (!empty($openRegRoleId)) {
            User::applyDefaultUserAppRole($user, $openRegRoleId);
        }

        return $user;
    }

    /**
     * @param           $user User
     * @param           $emailServiceId
     * @param           $emailTemplateId
     * @param bool|true $deleteOnError
     *
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    protected static function sendConfirmation($user, $emailServiceId, $emailTemplateId, $deleteOnError = true)
    {
        try {
            if (empty($emailServiceId)) {
                throw new InternalServerErrorException('No email service configured for user invite. See system configuration.');
            }

            if (empty($emailTemplateId)) {
                throw new InternalServerErrorException("No default email template for user invite.");
            }

            /** @var EmailService $emailService */
            $emailService = ServiceHandler::getServiceById($emailServiceId);
            $emailTemplate = EmailTemplate::find($emailTemplateId);

            if (empty($emailTemplate)) {
                throw new InternalServerErrorException("No data found in default email template for user invite.");
            }

            try {
                $email = $user->email;
                $code = \Hash::make($email);
                $user->confirm_code = base64_encode($code);
                $user->save();
                $templateData = $emailTemplate->toArray();
                $data = array_merge($templateData, [
                    'to'             => $email,
                    'confirm_code'   => $user->confirm_code,
                    'link'           => url(\Config::get('df.confirm_register_url')) . '?code=' . $user->confirm_code,
                    'first_name'     => $user->first_name,
                    'last_name'      => $user->last_name,
                    'name'           => $user->name,
                    'email'          => $user->email,
                    'phone'          => $user->phone,
                    'content_header' => ArrayUtils::get($templateData, 'subject', 'Confirm your DreamFactory account.'),
                    'instance_name'  => \Config::get('df.instance_name')
                ]);
            } catch (\Exception $e) {
                throw new InternalServerErrorException("Error creating user confirmation.\n{$e->getMessage()}",
                    $e->getCode());
            }

            $bodyText = $emailTemplate->body_text;
            if (empty($bodyText)) {
                //Strip all html tags.
                $bodyText = strip_tags($emailTemplate->body_html);
                //Change any multi spaces to a single space for clarity.
                $bodyText = preg_replace('/ +/', ' ', $bodyText);
            }

            $emailService->sendEmail($data, $bodyText, $emailTemplate->body_html);
        } catch (\Exception $e) {
            if ($deleteOnError) {
                $user->delete();
            }
            throw new InternalServerErrorException("Error processing user confirmation.\n{$e->getMessage()}",
                $e->getCode());
        }
    }
}
