<?php

namespace Swpider;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Arr;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\DomCrawler\Crawler;
use Swpider\Event\SpiderReadyEvent;



class Swpider extends Command
{
    const MAX_WAIT = 10;

    protected $logger;
    protected $job;
    protected $spider;
    protected $input;
    protected $output;
    protected $dispatcher;

    //主进程id
    protected $mpid = 0;
    //进程池
    private $workers = [];

    //当前进程
    private $active_worker;
    //队列任务的重试次数
    private $job_wait = 0;

    protected $spiders = [
        'test' => Spiders\Test::class,
    ];


    protected function configure()
    {
        $this->setName('run')
            ->setDescription('start a spider job')
            ->addOption('daemon','d', InputOption::VALUE_OPTIONAL, 'set daemon mode', false)
            ->addArgument('spider', InputArgument::REQUIRED, 'spider job');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->dispatcher = new EventDispatcher();
        Log::init($output);

        $this->setupSpider();
    }



    protected function setupSpider()
    {
        $spider = $this->input->getArgument('spider');

        if(! isset($this->spiders[$spider])){
            Log::error("Spider $spider not found!");
            exit(1);
        }

        $this->spider = new $this->spiders[$spider]($this);

        if($this->input->hasOption('daemon')){
            \swoole_process::daemon();
        }
        $this->initMaster();
    }

    //初始化主进程
    protected function initMaster()
    {
        $this->mpid = posix_getpid();
        swoole_set_process_name(sprintf('spider master:%s', $this->spider->name));

        Log::debug("master start at " . date("Y-m-d H:i:s"));
        Log::debug("master pid is {$this->mpid}");


        Log::debug("connecting queue...");
        Queue::connect($this->spider->getQueueConfig());


        $event = new SpiderReadyEvent($this, $this->input, $this->output);
        $this->dispatcher->dispatch('spider.ready', $event);

        //将索引地址写入请求队列
        foreach($this->spider->getIndexes() as $url){
            Log::debug("push url：{$url}");
            Queue::addIndex($url);
        }

        //开启爬虫进程
        for($i = 0; $i < $this->spider->task_num; $i++){
            $this->createWorker();
        }

        //开始观察进程
        //$this->createWatcher();

        //开始子进程监控
        $this->wait();
    }



    protected function createWorker()
    {
        $worker = new \swoole_process([new Worker($this), 'start']);
        $pid = $worker->start();
        $this->workers[$pid] = $worker;
    }


    //爬虫进程逻辑
    public function worker(\swoole_process $worker)
    {
        $this->active_worker = $worker;
        $this->spider->onStart();
        //清空子进程的进程数组
        unset($this->workers);

        //进程命名
        swoole_set_process_name(sprintf('spider pool:%s', $this->spider->name));

        //建立新的连接，避免多进程间相互抢占主进程的连接
        Queue::connect($this->spider->getQueueConfig());
        //建立数据库连接
        Database::connect($this->spider->getDatabaseConfig());
        //建立redis链接
        Cache::connect($this->spider->getRedisConfig());


        //操作队列
        $this->handleQueue();

    }

    //操作队列
    protected function handleQueue()
    {
        //从队列取任务, 如果长时间没有任务，则考虑关闭该进程
        while(1){

            $job = Queue::getUrl();

            if(! $job){

                if($this->job_wait < self::MAX_WAIT){
                    $this->job_wait++;
                    usleep(100);
                    continue;
                }else{
                    //超过重试次数，退出队列监听
                    break;
                }
            }

            $this->job_wait = 0;

            //解析任务
            $this->resolverJob($job);

            //检查主进程状态
            $this->checkMaster();
        }
    }


    //执行队列任务
    protected function resolverJob($job)
    {
        Log::debug("Requesting: {$job['url']}");

        $client = new Client([
            'time' => 2.0
        ]);

        try{
            $response = $client->get($job['url']);
        }catch(ClientException $e){
            if($this->needRetry($e)){
                return false;
            }

            Queue::releaseUrl($job);
            throw $e;
        }

        Log::debug("Requested: {$job['url']}");


        //解析网页内容
        $rules = $this->spider->getRules();
        $content = $response->getBody()->getContents();
        foreach($rules['url'] as $name=>$option){
            $regex = $option['regex'];
            //解析可用链接
            if(preg_match_all("#{$regex}#iu", $content, $matches)){
                foreach($matches[0] as $url){
                    //检查是否可用链接
                    if(! $this->isEnableUrl($url, $option['reentry'])){
                        Log::debug("disable url: $url");
                        continue;
                    }

                    //加入队列
                    Queue::addUrl($url, $name);
                    //写入缓存
                    Cache::setUrl($url,0);
                }
            }
        }


        $fields = Arr::get($rules,'url.'.$job['type'].'.fields', false);

        //解析字段
        if($job['type'] !== 'index' && !empty($fields)){

            $data = [
                'type' => $job['type']
            ];
            $crawler = new Crawler($content);
            foreach($fields as $field){
                $rule = $rules['fields'][$field];

                $re = $crawler->filter($rule['selector']);
                $value_rule = Arr::get($rule, 'value', 'text');

                if(Arr::get($rule, 'multi', false)){
                    $value = [];
                    $re->each(function($node) use ($value_rule, &$value){
                        $value[] = $this->getValue($value_rule,$node);
                    });
                }else{
                    $value = $this->getValue($value_rule,$re);
                }

                $data['data'][$field] = $value;
            }

            $this->spider->onResponse($response, $data);
        }


        //移出队列
        Queue::deleteUrl($job);
        //更新缓存
        Cache::setUrl($job['url'], 1);

    }

    protected function needRetry()
    {
        return false;
    }

    /**
     * 获取节点值
     * @param $rule
     * @param Crawler $node
     * @return null|string
     */
    protected function getValue($rule,Crawler $node)
    {
        if(strpos($rule, '@') === 0){
            return $node->attr(substr($rule,1));
        }

        return $node->text();
    }


    protected function isEnableUrl($url, $reentry = false)
    {
        //不存在链接集合中，或者已过了重入时间间隔且已经请求过
        $data = Cache::getUrl($url);

        return ! $data ||
            ( $reentry !== false
                && $data['status'] !== 0
                && time() - $data['last'] > $reentry );

    }

    public function getDispatcher()
    {
        return $this->dispatcher;
    }

    /**
     * 监控主进程状态
     */
    protected function checkMaster()
    {
        if(!\swoole_process::kill($this->mpid,0)){
            Log::alert("Master process exited, Children process {$this->active_worker->pid} exit at " . date('Y-m-d H:i:s'));
            $this->active_worker->exit(0);
        }
    }



    //检查主进程是否已结束
    protected function watchMaster(\swoole_process &$worker)
    {
        if(! \swoole_process::kill($this->mpid, 0)){
            $worker->exit(0);
            Log::notice("Master process exited! Process {$worker['pid']} quit now.");
        }
    }


    protected function wait()
    {
        while(1){
            if(count($this->workers)){
                $ret = \swoole_process::wait();
                if($ret){
                    //从集合中剔除
                    unset($this->workers[$ret['pid']]);
                    //新建进程，保证进程数
                    //$this->createWorker();
                }
            }else{
                break;
            }
        }
    }


}
