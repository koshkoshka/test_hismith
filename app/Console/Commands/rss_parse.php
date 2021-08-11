<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Models\ParserLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use SimpleXMLElement;
use XMLReader;

class rss_parse extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:rss_parse';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse RSS RBC.ru';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $url='http://static.feed.rbc.ru/rbc/logical/footer/news.rss';
        $curl=curl_init();
        curl_setopt($curl,CURLOPT_URL,$url);
        curl_setopt($curl,CURLOPT_RETURNTRANSFER,true);
        curl_setopt($curl,CURLOPT_FOLLOWLOCATION,true);
        curl_setopt($curl,CURLOPT_SSL_VERIFYPEER,false);
        curl_setopt($curl,CURLOPT_AUTOREFERER, true);
        curl_setopt($curl,CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/71.0.3578.98 Safari/537.36');
        $xmlstr=curl_exec($curl);
        $response_http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $response_size = curl_getinfo($curl, CURLINFO_SIZE_DOWNLOAD);
        $response_content_type = strtolower(curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
        $curl_error = curl_error($curl);
        curl_close($curl);

        ParserLog::create([
            'method'=>'GET',
            'url'=>$url,
            'code'=>$response_http_code,
            'body'=>$xmlstr,
        ]);

        if (!empty($curl_error) || $response_http_code<200 || $response_http_code>=400)return $response_http_code;

        $reader = new XMLReader;
        $openstatus = $reader->XML($xmlstr, 'UTF-8');
        if (!$openstatus) {
            $reader->close();
            return false;
        }
        $items=[];
        while ($rd = $reader->read()) {
            if($reader->name=='item' && $reader->nodeType==XMLReader::ELEMENT){
                $items[]=$reader->readOuterXML();//Помещаем все object в массив
            }
        }
        $reader->close();
        foreach ($items as $ofr) {
            $item = new SimpleXMLElement($ofr, LIBXML_NOCDATA);//Обрабатываем каждый offer в Simple XML
            $title=$item->title;
            $link=$item->link;
            $dsc=$item->description;
            $author=$item->author??null;
            $guid=$item->guid;
            $pubdate=Carbon::parse($item->pubDate);
            $img=null;
            foreach($item->enclosure as $attach){
                /** @var SimpleXMLElement $attach */
                $type=(string)($attach['type']??null);
                if(mb_stripos($type,'image/')!==false){
                    $img=$attach['url']??null;
                    break;//Первой картинки достаточно
                }
            }
            News::updateOrCreate([
                'guid'=>$guid,
            ],[
                'title'=>$title,
                'url'=>$link,
                'author'=>$author,
                'announce'=>$dsc,
                'pubdate'=>$pubdate,
                'head_image'=>$img,
            ]);
        }

        return 0;
    }
}
