<?php
/**
 * Created by PhpStorm.
 * User: matt
 * Date: 6/7/14
 * Time: 11:58 AM
 */

namespace AutobahnPHP\Peer;


use AutobahnPHP\AbstractSession;
use AutobahnPHP\ClientSession;
use AutobahnPHP\Message\AbortMessage;
use AutobahnPHP\Message\AuthenticateMessage;
use AutobahnPHP\Message\ChallengeMessage;
use AutobahnPHP\Message\ErrorMessage;
use AutobahnPHP\Message\GoodbyeMessage;
use AutobahnPHP\Message\HelloMessage;
use AutobahnPHP\Message\Message;
use AutobahnPHP\Message\WelcomeMessage;
use AutobahnPHP\Realm;
use AutobahnPHP\Role\AbstractRole;
use AutobahnPHP\Role\Callee;
use AutobahnPHP\Role\Caller;
use AutobahnPHP\Role\Publisher;
use AutobahnPHP\Role\Subscriber;
use AutobahnPHP\Session;
use AutobahnPHP\Transport\AbstractTransportProvider;
use AutobahnPHP\Transport\TransportInterface;
use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;

/**
 * Class Client
 * @package AutobahnPHP
 */
class Client extends AbstractPeer implements EventEmitterInterface
{
    use EventEmitterTrait;

    /**
     * @var
     */
    private $roles;

    /**
     * @var Callee
     */
    private $callee;

    /**
     * @var Caller
     */
    private $caller;

    /**
     * @var Publisher
     */
    private $publisher;

    /**
     * @var Subscriber
     */
    private $subscriber;


    /**
     * @var AbstractTransportProvider
     */
    private $transportProvider;

    /**
     * @var ClientSession
     */
    private $session;

    /**
     * @var \React\EventLoop\ExtEventLoop|\React\EventLoop\LibEventLoop|\React\EventLoop\LibEvLoop|\React\EventLoop\LoopInterface|\React\EventLoop\StreamSelectLoop
     */
    private $loop;

    /**
     * @var string
     */
    private $realm;

    /**
     * @var Array
     */
    private $authMethods;

    /**
     * @var TransportInterface
     */
    private $transport;

    function __construct($realm, LoopInterface $loop = null)
    {
        $this->transportProvider = null;
        $this->roles = array();
        $this->realm = $realm;
        $this->authMethods = array();

        if ($loop === null) {
            $loop = Factory::create();
        }

        $this->loop = $loop;
    }

    /**
     * @param AbstractRole $role
     * @return $this
     */
    public function addRole(AbstractRole $role)
    {

        if ($role instanceof Publisher):
            $this->publisher = $role;
        elseif ($role instanceof Subscriber):
            $this->subscriber = $role;
        elseif ($role instanceof Callee):
            $this->callee = $role;
        elseif ($role instanceof Caller):
            $this->caller = $role;
        endif;

        array_push($this->roles, $role);

        return $this;
    }

    public function addAuthMethod(Array $authMethod)
    {
        $this->authMethods = $this->authMethods + $authMethod;
    }

    /**
     * @param ClientSession $session
     */
    public function startSession(ClientSession $session)
    {
        $details = [
            "roles" => [
                "publisher" => new \stdClass(),
                "subscriber" => new \stdClass(),
                "caller" => new \stdClass(),
                "callee" => new \stdClass(),
            ]
        ];

        $details["authmethods"] = array_keys($this->authMethods);

        $this->addRole(new Callee($session))
            ->addRole(new Caller($session))
            ->addRole(new Publisher($session))
            ->addRole(new Subscriber($session));

        $session->setRealm($this->realm);

        $session->sendMessage(new HelloMessage($session->getRealm(), $details, array()));
    }

    public function onOpen(TransportInterface $transport)
    {
        if ($this->session !== null) {
            throw new \Exception("There is already an attached session?");
        }

        $this->transport = $transport;
        $session = new ClientSession($transport, $this);
        $this->session = $session;
        $this->startSession($session);

    }

    public function onMessage(TransportInterface $transport, Message $msg)
    {

        echo "Client onMessage!\n";

        $session = $this->session;

        if ($msg instanceof WelcomeMessage):
            $this->processWelcome($session, $msg);
        elseif ($msg instanceof AbortMessage):
            $this->processAbort($session, $msg);
        elseif ($msg instanceof GoodbyeMessage):
            $this->processGoodbye($session, $msg);
        //advanced
        elseif ($msg instanceof ChallengeMessage):
            $this->processChallenge($session, $msg);
        else:
            $this->processOther($session, $msg);
        endif;


    }

    /**
     * @param ClientSession $session
     * @param WelcomeMessage $msg
     */
    public function processWelcome(ClientSession $session, WelcomeMessage $msg)
    {
        //TODO: I'm sure that there are some other things that we need to do here
        $session->setSessionId($msg->getSessionId());
        $this->emit('open', [$session, $this->transport]);
    }

    /**
     * @param ClientSession $session
     * @param AbortMessage $msg
     */
    public function processAbort(ClientSession $session, AbortMessage $msg)
    {
        //TODO:  Implement this
    }

    /**
     * @param ClientSession $session
     * @param ChallengeMessage $msg
     */
    public function processChallenge(ClientSession $session, ChallengeMessage $msg)
    {

        $authmethod = $msg->getAuthMethod();
        $signature = $this->authMethods[$authmethod]['callback']($session, $authmethod, $msg->getExtra());

        $authenticateMsg = new AuthenticateMessage($signature);

        $session->sendMessage($authenticateMsg, $msg->getExtra());
    }

    /**
     * @param ClientSession $session
     * @param GoodbyeMessage $msg
     */
    public function processGoodbye(ClientSession $session, GoodbyeMessage $msg)
    {
        //TODO:  Implement this
    }

    /**
     * @param ClientSession $session
     * @param Message $msg
     */
    public function processOther(ClientSession $session, Message $msg)
    {
        /* @var $role AbstractRole */
        foreach ($this->roles as $role) {
            if ($role->handlesMessage($msg)) {
                $role->onMessage($session, $msg);
                break;
            }
        }
    }

    /**
     * @return Callee
     */
    public function getCallee()
    {
        return $this->callee;
    }


    /**
     * @return Caller
     */
    public function getCaller()
    {
        return $this->caller;
    }


    /**
     * @return Publisher
     */
    public function getPublisher()
    {
        return $this->publisher;
    }


    /**
     * @return Subscriber
     */
    public function getSubscriber()
    {
        return $this->subscriber;
    }


    /**
     * @return array
     */
    public function getRoles()
    {
        return $this->roles;
    }

    public function addTransportProvider(AbstractTransportProvider $transportProvider)
    {
        if ($this->transportProvider !== null) {
            throw new \Exception("You can only have one transport provider for a client");
        }
        $this->transportProvider = $transportProvider;
    }

    public function start()
    {
        $this->transportProvider->startTransportProvider($this, $this->loop);

        $this->loop->run();
    }

    public function onClose(TransportInterface $transport)
    {

        $this->session->onClose();

//        $loop = $this->loop;
//
//        $this->loop->addTimer(60, function () use ($loop) {
//                // add another time on fail
//            } );
    }
}