<?php

use ChurchCRM\Authentication\AuthenticationManager;
use ChurchCRM\dto\Cart;
use ChurchCRM\dto\ChurchMetaData;
use ChurchCRM\dto\SystemConfig;
use ChurchCRM\model\ChurchCRM\PersonQuery;
use ChurchCRM\Slim\Middleware\Request\Auth\EmailRoleAuthMiddleware;
use ChurchCRM\Slim\SlimUtils;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

$app->group('/cart', function (RouteCollectorProxy $group): void {
    /**
     * @OA\Get(
     *     path="/cart/",
     *     summary="Get the current session people cart contents",
     *     tags={"Cart"},
     *     security={{"ApiKeyAuth":{}}},
     *     @OA\Response(response=200, description="Array of person IDs currently in the cart",
     *         @OA\JsonContent(@OA\Property(property="PeopleCart", type="array", @OA\Items(type="integer")))
     *     )
     * )
     */
    $group->get('/', function (Request $request, Response $response, array $args): Response {
        // Ensure cart session exists
        if (!isset($_SESSION['aPeopleCart'])) {
            $_SESSION['aPeopleCart'] = [];
        }
        // Validate cart contents are numeric (defense in depth)
        $validCart = array_filter($_SESSION['aPeopleCart'], 'is_numeric');
        $cart = ['PeopleCart' => array_map('intval', $validCart)];
        return SlimUtils::renderJSON($response, $cart);
    });

    /**
     * @OA\Post(
     *     path="/cart/",
     *     summary="Add persons, a family, or a group to the session cart",
     *     tags={"Cart"},
     *     security={{"ApiKeyAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="Persons", type="array", @OA\Items(type="integer"), description="Array of person IDs to add"),
     *             @OA\Property(property="Family", type="integer", description="Family ID to add all members from"),
     *             @OA\Property(property="Group", type="integer", description="Group ID to add all members from")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Persons, family, or group added to cart"),
     *     @OA\Response(response=400, description="Invalid or missing request data")
     * )
     */
    $group->post('/', function (Request $request, Response $response, array $args): Response {
        try {
            $cartPayload = $request->getParsedBody();
            $result = null;

            if (isset($cartPayload['Persons']) && is_array($cartPayload['Persons']) && count($cartPayload['Persons']) > 0) {
                // Validate all Person IDs are numeric
                foreach ($cartPayload['Persons'] as $personId) {
                    if (!is_numeric($personId)) {
                        $e = new \Exception('Invalid Person ID in array: ' . json_encode($personId));
                        return SlimUtils::renderErrorJSON($response, gettext('Invalid request data'), [], 400, $e, $request);
                    }
                }
                // Cast to integers for safety
                $validPersonIds = array_map('intval', $cartPayload['Persons']);
                $result = Cart::addPersonArray($validPersonIds);
            } elseif (isset($cartPayload['Family'])) {
                // Validate Family ID is numeric
                if (!is_numeric($cartPayload['Family'])) {
                    $e = new \Exception('Invalid Family ID: ' . json_encode($cartPayload['Family']));
                    return SlimUtils::renderErrorJSON($response, gettext('Invalid request data'), [], 400, $e, $request);
                }
                $result = Cart::addFamily((int)$cartPayload['Family']);
            } elseif (isset($cartPayload['Group'])) {
                // Validate Group ID is numeric
                if (!is_numeric($cartPayload['Group'])) {
                    $e = new \Exception('Invalid Group ID: ' . json_encode($cartPayload['Group']));
                    return SlimUtils::renderErrorJSON($response, gettext('Invalid request data'), [], 400, $e, $request);
                }
                Cart::addGroup((int)$cartPayload['Group']);
        } else {
            $e = new \Exception('Missing required field: Persons, Family, or Group');
            return SlimUtils::renderErrorJSON($response, gettext('Invalid request data'), [], 400, $e, $request);
        }

            // Return result with added/duplicate information if available
            if ($result !== null) {
                return SlimUtils::renderJSON($response, $result);
            }

            return SlimUtils::renderSuccessJSON($response);
        } catch (\Throwable $e) {
            return SlimUtils::renderErrorJSON($response, gettext('Invalid request data'), [], 400, $e, $request);
        }
    });

    /**
     * @OA\Post(
     *     path="/cart/emptyToGroup",
     *     summary="Move all persons in the cart into a group with a specified role",
     *     tags={"Cart"},
     *     security={{"ApiKeyAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"groupID","groupRoleID"},
     *             @OA\Property(property="groupID", type="integer"),
     *             @OA\Property(property="groupRoleID", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Cart persons added to the group"),
     *     @OA\Response(response=400, description="Invalid or missing groupID/groupRoleID")
     * )
     */
    $group->post('/emptyToGroup', function (Request $request, Response $response, array $args): Response {
        try {
            $cartPayload = $request->getParsedBody();

            // Validate required fields exist
            if (!isset($cartPayload['groupID']) || !isset($cartPayload['groupRoleID'])) {
                $e = new \Exception('Missing required parameters: groupID=' . (isset($cartPayload['groupID']) ? 'set' : 'missing') . ', groupRoleID=' . (isset($cartPayload['groupRoleID']) ? 'set' : 'missing'));
                return SlimUtils::renderErrorJSON($response, gettext('Invalid request data'), [], 400, $e, $request);
            }

            // Validate groupID is numeric
            if (!is_numeric($cartPayload['groupID'])) {
                $e = new \Exception('Invalid groupID: ' . json_encode($cartPayload['groupID']));
                return SlimUtils::renderErrorJSON($response, gettext('Invalid request data'), [], 400, $e, $request);
            }

            // Validate groupRoleID is numeric
            if (!is_numeric($cartPayload['groupRoleID'])) {
                $e = new \Exception('Invalid groupRoleID: ' . json_encode($cartPayload['groupRoleID']));
                return SlimUtils::renderErrorJSON($response, gettext('Invalid request data'), [], 400, $e, $request);
            }

            Cart::emptyToGroup((int)$cartPayload['groupID'], (int)$cartPayload['groupRoleID']);

            return SlimUtils::renderJSON($response, [
            'status' => 'success',
            'message' => gettext('record(s) successfully added to selected Group.')
        ]);
        } catch (\Throwable $e) {
            return SlimUtils::renderErrorJSON($response, gettext('Invalid request data'), [], 400, $e, $request);
        }
    });

    /**
     * @OA\Post(
     *     path="/cart/removeGroup",
     *     summary="Remove all members of a group from the session cart",
     *     tags={"Cart"},
     *     security={{"ApiKeyAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"Group"},
     *             @OA\Property(property="Group", type="integer", description="Group ID whose members should be removed from the cart")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Group members removed from cart"),
     *     @OA\Response(response=400, description="Invalid or missing Group ID")
     * )
     */
    $group->post('/removeGroup', function (Request $request, Response $response, array $args): Response {
        try {
            $cartPayload = $request->getParsedBody();

            // Validate required field exists
            if (!isset($cartPayload['Group'])) {
                $e = new \Exception('Missing required parameter: Group');
                return SlimUtils::renderErrorJSON($response, gettext('Invalid request data'), [], 400, $e, $request);
            }

            // Validate Group is numeric
            if (!is_numeric($cartPayload['Group'])) {
                $e = new \Exception('Invalid Group ID: ' . json_encode($cartPayload['Group']));
                return SlimUtils::renderErrorJSON($response, gettext('Invalid request data'), [], 400, $e, $request);
            }

            Cart::removeGroup((int)$cartPayload['Group']);
            return SlimUtils::renderJSON($response, [
                'status' => 'success',
                'message' => gettext('record(s) successfully deleted from the selected Group.'),
            ]);
        } catch (\Throwable $e) {
            return SlimUtils::renderErrorJSON($response, gettext('Invalid request data'), [], 400, $e, $request);
        }
    });

    /**
     * @OA\Delete(
     *     path="/cart/",
     *     summary="Remove persons or a family from the cart, or empty the entire cart",
     *     tags={"Cart"},
     *     security={{"ApiKeyAuth":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="Persons", type="array", @OA\Items(type="integer"), description="Person IDs to remove"),
     *             @OA\Property(property="Family", type="integer", description="Family ID — removes all family members from cart"),
     *             description="Omit body to empty the entire cart"
     *         )
     *     ),
     *     @OA\Response(response=200, description="Cart updated",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=400, description="Invalid request data")
     * )
     */
    /**
     * delete. This will empty the cart.
     */
    $group->delete('/', function (Request $request, Response $response, array $args): Response {
        try {
            $cartPayload = $request->getParsedBody();
            $sMessage = gettext('Your cart is empty');
            if (isset($cartPayload['Persons']) && is_array($cartPayload['Persons']) && count($cartPayload['Persons']) > 0) {
                // Validate all IDs are numeric
                foreach ($cartPayload['Persons'] as $personId) {
                    if (!is_numeric($personId)) {
                        $e = new \Exception('Invalid Person ID in array: ' . json_encode($personId));
                        return SlimUtils::renderErrorJSON($response, gettext('Invalid request data'), [], 400, $e, $request);
                    }
                }
                // Cast to integers for safety
                $validPersonIds = array_map('intval', $cartPayload['Persons']);
                Cart::removePersonArray($validPersonIds);
                $sMessage = gettext('Person(s) removed from cart');
            } elseif (isset($cartPayload['Family'])) {
                // Validate Family ID is numeric
                if (!is_numeric($cartPayload['Family'])) {
                    $e = new \Exception('Invalid Family ID: ' . json_encode($cartPayload['Family']));
                    return SlimUtils::renderErrorJSON($response, gettext('Invalid request data'), [], 400, $e, $request);
                }
                Cart::removeFamily((int)$cartPayload['Family']);
                $sMessage = gettext('Family removed from cart');
            } else {
                if (count($_SESSION['aPeopleCart']) > 0) {
                    $_SESSION['aPeopleCart'] = [];
                    $sMessage = gettext('Your cart has been successfully emptied');
                }
            }

            return SlimUtils::renderJSON($response, [
                'status' => 'success',
                'message' => $sMessage,
            ]);
        } catch (\Throwable $e) {
            return SlimUtils::renderErrorJSON($response, gettext('Invalid request data'), [], 400, $e, $request);
        }
    });

    /**
     * @OA\Post(
     *     path="/cart/send-email",
     *     summary="Send an email to cart members via the configured SMTP",
     *     tags={"Cart"},
     *     security={{"ApiKeyAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="to", type="string", description="Comma-separated recipient email addresses"),
     *             @OA\Property(property="subject", type="string"),
     *             @OA\Property(property="body", type="string"),
     *             @OA\Property(property="individual", type="boolean", default=false)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Email sent successfully"),
     *     @OA\Response(response=403, description="Permission denied or email not configured")
     * )
     */
    $group->post('/send-email', function (Request $request, Response $response, array $args): Response {
        $input = (array) $request->getParsedBody();
        $toRaw = $input['to'] ?? '';
        $subject = trim($input['subject'] ?? '');
        $body = trim($input['body'] ?? '');
        $individual = !empty($input['individual']);

        if (empty($toRaw)) {
            return SlimUtils::renderErrorJSON($response, gettext('No recipient email addresses provided'), [], 400);
        }
        if (empty($subject) && empty($body)) {
            return SlimUtils::renderErrorJSON($response, gettext('Subject and body cannot both be empty'), [], 400);
        }

        // Split on comma or semicolon (frontend normalizes, but be safe)
        $toAddresses = array_filter(array_map('trim', preg_split('/[,;]/', $toRaw)), function ($email) {
            return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
        });

        if (empty($toAddresses)) {
            return SlimUtils::renderErrorJSON($response, gettext('No valid email addresses provided'), [], 400);
        }

        if (!SystemConfig::isEmailEnabled()) {
            return SlimUtils::renderErrorJSON($response, gettext('Email is not configured on this system'), [], 400);
        }

        try {
            $mailerConfig = function () {
                $m = new \PHPMailer\PHPMailer\PHPMailer();
                $m->IsSMTP();
                $m->CharSet = 'UTF-8';
                $m->Timeout = SystemConfig::getIntValue('iSMTPTimeout');
                $m->Host = SystemConfig::getValue('sSMTPHost');
                $m->SMTPAutoTLS = SystemConfig::getBooleanValue('bPHPMailerAutoTLS');
                $m->SMTPSecure = SystemConfig::getValue('sPHPMailerSMTPSecure');
                if (SystemConfig::getBooleanValue('bSMTPAuth')) {
                    $m->SMTPAuth = true;
                    $m->Username = SystemConfig::getValue('sSMTPUser');
                    $m->Password = SystemConfig::getValue('sSMTPPass');
                }
                $m->SMTPDebug = 0;
                $m->setFrom(
                    ChurchMetaData::getChurchEmail(),
                    ChurchMetaData::getChurchName()
                );
                $currentUser = AuthenticationManager::getCurrentUser();
                $userEmail = trim((string) $currentUser->getEmail());
                if (!empty($userEmail)) {
                    $m->addReplyTo($userEmail, $currentUser->getFullName());
                }
                return $m;
            };

            if ($individual) {
                // Build email→name map — first from cart people, then fall back to PersonQuery
                $emailNames = [];
                $cartPeople = \ChurchCRM\dto\Cart::getCartPeople();
                foreach ($cartPeople as $person) {
                    $peEmail = strtolower(trim((string) $person->getEmail()));
                    if (!empty($peEmail)) {
                        $emailNames[$peEmail] = [
                            'firstName' => $person->getFirstName(),
                            'lastName'  => $person->getLastName(),
                        ];
                    }
                }

                // Fallback: query Person for any emails not found in the cart
                foreach ($toAddresses as $email) {
                    $lookup = strtolower(trim($email));
                    if (!isset($emailNames[$lookup])) {
                        $person = \ChurchCRM\model\ChurchCRM\PersonQuery::create()
                            ->filterByEmail($email)
                            ->findOne();
                        if ($person !== null) {
                            $emailNames[$lookup] = [
                                'firstName' => $person->getFirstName(),
                                'lastName'  => $person->getLastName(),
                            ];
                        }
                    }
                }

                $sentCount = 0;
                $failCount = 0;
                $failEmails = [];
                foreach ($toAddresses as $email) {
                    $mailer = $mailerConfig();
                    $mailer->addAddress($email);

                    $lookup = strtolower(trim($email));
                    $firstName = $emailNames[$lookup]['firstName'] ?? '';
                    $lastName  = $emailNames[$lookup]['lastName'] ?? '';
                    $personalSubject = str_replace(
                        ['{firstName}', '{lastName}'],
                        [$firstName, $lastName],
                        $subject
                    );
                    $personalBody = str_replace(
                        ['{firstName}', '{lastName}'],
                        [$firstName, $lastName],
                        $body
                    );

                    $mailer->Subject = $personalSubject;
                    $mailer->Body = nl2br($personalBody);
                    $mailer->AltBody = $personalBody;
                    $mailer->isHTML(true);
                    if ($mailer->send()) {
                        $sentCount++;
                    } else {
                        $failCount++;
                        $failEmails[] = $email;
                    }
                }
                if ($sentCount > 0) {
                    $msg = sprintf(gettext('Email sent to %d of %d recipients'), $sentCount, count($toAddresses));
                    if ($failCount > 0) {
                        $msg .= ' ' . sprintf(gettext('(%d failed)'), $failCount);
                    }
                    return SlimUtils::renderJSON($response, [
                        'success' => true,
                        'message' => $msg,
                        'sentCount' => $sentCount,
                        'failCount' => $failCount,
                        'failEmails' => $failEmails,
                    ]);
                }
                return SlimUtils::renderErrorJSON($response, gettext('Failed to send any emails'), [], 500);
            }

            // Default: single email to all recipients
            $phpMailer = $mailerConfig();
            foreach ($toAddresses as $email) {
                $phpMailer->addAddress($email);
            }
            $phpMailer->Subject = $subject;
            $phpMailer->Body = nl2br($body);
            $phpMailer->AltBody = $body;
            $phpMailer->isHTML(true);

            if ($phpMailer->send()) {
                return SlimUtils::renderJSON($response, [
                    'success' => true,
                    'message' => gettext('Email sent successfully'),
                    'recipientCount' => count($toAddresses),
                ]);
            }

            return SlimUtils::renderErrorJSON($response, $phpMailer->ErrorInfo, [], 500);
        } catch (\Throwable $e) {
            return SlimUtils::renderErrorJSON($response, gettext('Failed to send email'), [], 500, $e, $request);
        }
    })->add(EmailRoleAuthMiddleware::class);
});
