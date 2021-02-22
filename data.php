<?php
	ini_set('max_execution_time', 0);
	ini_set('memory_limit', -1);
	ini_set('date.timezone', 'Asia/Taipei');

	require(__DIR__ . '/phpQuery/phpQuery.php');

	$proxies = json_decode(file_get_contents('proxies.json'), true);

	class RssWorker {
		private $source;
		private $rss;
		private $key;

		public function __construct ($source, $rss, $key) {
			$this->source = $source;
			$this->rss = $rss;
			$this->key = $key;
		}

		public function run () {
			file_put_contents(__DIR__ . '/temp/r2.' . $this->key, json_encode($this->postProcess($this->filter($this->fetch()))));
		}

		private function fetch () {
			global $proxies;

			$rss = $this->rss;
			$url = $rss['url'];

			try {
				$xml = file_get_contents($url);

				if ($xml === false) {
					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $url);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($ch, CURLOPT_PROXY, $proxies[rand(0, count($proxies) - 1)]);
					$xml = curl_exec($ch);
				}

				$doc = phpQuery::newDocumentXML($xml);
			} catch (Exception $e) {
				echo "Loading RSS Fialed: $url\n";
			}

			$data = array();
			$source = $this->source;

			foreach($doc['channel item'] as $item) {
				$item = pq($item);
				$pubDate = $item['pubDate']->eq(0)->text();

				if (is_numeric($pubDate)) {
					$timestamp = intval($pubDate);
				}
				else {
					$timestamp = strtotime(str_replace(array('年', '月', '日'), array('/', '/', ''), $pubDate));
				}

				$link = $item['link']->eq(0)->text();
				$description = $item['description']->text();
				$image = $item['image url']->eq(0)->text();

				$ref =& $data[];
				$ref = array(
						'title' => trim(str_replace('　', ' ', $item['title']->eq(0)->text())),
						'link' => $link,
						'timestamp' => $timestamp,
						'description' => $description,
						'source' => $source
					);

				if ($image !== '') {
					$ref['image'] = $image;
				}

				$ref['rss'] = $rss;
			}

			return $data;
		}

		private function filter ($data) {
			$data2 = array();

			foreach ($data as $item) {
				if ($item['source'] === 'libertytimes' && strpos($item['link'], '/paper/') !== false) {
					continue;
				}

				$data2[] = $item;
			}

			return $data2;
		}

		private function postProcess($data) {
			$path = __DIR__ . '/cache/redirect.json';
			$map = file_exists($path) ?	json_decode(file_get_contents($path), true) : array();
			$map2 = array();

			foreach ($data as &$item) {
				$source = $item['source'];
				$link = $item['link'];
				$description = $item['description'];

				if (isset($item['image'])) {
					$image = $item['image'];
				}

				if (substr($link, 0, 31) === 'http://feedproxy.google.com/~r/' ||
					substr($link, 0, 29) === 'http://feeds.initium.news/~r/') {
					$origin = $link;

					if (isset($map[$origin])) {
						$link = $map[$origin];
					}
					else {
						$ch = curl_init();
						curl_setopt($ch, CURLOPT_URL, $origin);
						curl_setopt($ch, CURLOPT_HEADER, true);
						curl_setopt($ch, CURLOPT_NOBODY, true);
						curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
						preg_match('@^Location: (.*)$@m', curl_exec($ch), $matches);

						$link = $matches[1];

						$pos = strpos($link, 'utm_source');

						if ($pos !== false) {
							$link = substr($link, 0, $pos - 1);
						}
					}

					$map2[$origin] = $link;
					$description = preg_replace('/<img src="(https?:)?\/\/feeds.feedburner.com\/~r\/[^>]+>/', '', $description);
				}

				switch ($source) {
					case 'ettoday':
						if (($pos = strpos($description, '<div class="feedflare">')) !== false) {
							$description = substr($description, 0, $pos);
							$description = str_replace('<a href="' . $link . '?from=rss" target="_blank">《詳全文...》</a>', '', $description);
							$description = preg_replace('/(http:\/\/static.ettoday.net\/images\/\d+\/)b(\d+.jpg)/', '$1d$2', $description);
						}
						break;
					case 'chinatimes':
						$description = str_replace('<img src="//cache.chinatimes.com/images/logo-1200x635.jpg">', '', $description) . '...';
						$description = preg_replace('/<img src="[^"]+"[^>]*>/', '$0<br>', $description);
						$category = $item['rss']['label'];
						break;
					case 'udn':
						$description = preg_replace(array(
							'/<div class="photo_pop">.*?<\/div>/s',
							'/<div class="video-container">.*?<\/div>/s',
							'/<link href="[^>]+>/'), '', $description);

						$description = str_replace(array('<h4>', '</h4>', '<a href="####" class="photo_pop_icon">分享</a>', '...'), array('<div>', '</div>', '', ''), $description);

						$description = preg_replace(
								'/\'https:\\/\\/pgw\\.udn\\.com\\.tw\\/gw\\/photo\\.php\\?u=([^&]*)&[^\']*\'>/',
								'<img src="$1">',
								$description
							);

						$category = $item['rss']['label'];
						break;
					case 'libertytimes':
						$category = $item['rss']['label'];
						break;
					case 'cna':
						$description = str_replace("<br/>\n<br/>\n[[ 此摘要項目的內容經過刪剪，請瀏覽我的網站以檢視全部連結和內容。 ]]", '', $description);
						$category = $item['rss']['label'];
						break;
					case 'storm':
						if (substr($link, 0, 28) === 'http://www.storm.mg/article/' && ($pos = strpos($link, '/', 28)) !== false) {
							$link = substr($link, 0, $pos);
						}

						if (isset($image)) {
							$origin = $image;

							if (isset($map[$origin])) {
								$image = $map[$origin];
							}
							else {
								$ch = curl_init();
								curl_setopt($ch, CURLOPT_URL, $image);
								curl_setopt($ch, CURLOPT_NOBODY, true);
								curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
								curl_exec($ch);
								$image = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

								if (substr($image, 0, 7) === 'http://') {
									$image = 'https://' . substr($image, 7);
								}

								$map2[$origin] = $image;
							}
						}

						$category = $item['rss']['label'];
						break;
					case 'newtalk':
						$category = $item['rss']['label'];
						$description = '';
				}

				$description = preg_replace(
						array(
							'/<!--(.*?)-->/s',
							'#<img([^>]*) src=["\']http://([^"\']*)["\']([^>]*)>#',
							'/\.\.\./',
							'/…/u'
						),
						array(
							'',
							'<img$1 src="https://i1.wp.com/$2"$3>',
							' ... ',
							' ... '
						),
						$description
					);

				$item['link'] = $link;

				$tidy = new tidy();
				$description = $tidy->repairString($description, array('show-body-only' => true), 'utf8');
				$item['description'] = $description;

				if (isset($category)) {
					$item['category'] = $category;
				}

				if (isset($image)) {
					if (substr($image, 0, 7) === 'http://') {
						$image = 'https://i2.wp.com/' . substr($image, 7);
					}

					$item['image'] = $image;
				}

				unset($item['rss']);
			}

			if (count($map2) > 0) {
				file_put_contents(__DIR__ . '/temp/r3.' . $this->key, json_encode($map2));
			}

			return $data;
		}
	}

	class PageWorker {
		private $source;

		public function __construct ($source) {
			$this->source = $source;
		}

		public function run () {
			$source = $this->source;
			file_put_contents(__DIR__ . '/temp/p.' . $source, json_encode($this->$source()));
		}

		private function libertytimes () {
			$map = array(
					'focus' => '焦點',
					'politics' => '政治',
					'society' => '社會',
					'local' => '地方',
					'life' => '生活',
					'talk' => '言論',
					'world' => '國際',
					'business' => '財經',
					'sports' => '體育',
					'ent' => '娛樂',
					'entertainment' => '娛樂',
					'consumer' => '消費',
					'supplement' => '副刊',
					'istyle' => 'iStyle',
					'3c' => '3C',
					'auto' => '汽車',
					'playing' => '玩咖'
				);

			$data = array();

			for ($i = 1; $i <= 10; ++$i) {
				$url = 'http://news.ltn.com.tw/list/breakingnews/all/' . $i;
				$doc = phpQuery::newDocument(file_get_contents($url));

				foreach ($doc['.list.imm > li'] as $li) {
					$li = pq($li);
					$anchor2 = $li->children('a');
					$anchor = $anchor2->eq(0);
					$anchor2 = $anchor2->eq(1);

					$href = $anchor->attr('href');
					$tokens = explode('/', $href);

					if (count($tokens) === 1) {
						continue;
					}

					$token = $tokens[2];
					$domain = substr($token, 0, strpos($token, '.'));
					$category = $map[$domain === 'news' ? $tokens[4] : $domain];

					$item = array(
							'title' => trim($anchor2->children('p')->text()),
							'link' => $href,
							'category' => $category,
							'timestamp' => strtotime($anchor2->children('span')->text()),
							'description' => '',
							'source' => 'libertytimes'
						);

					$image = $anchor->children('img')->attr('src');

					if ($image !== '') {
						if (substr($image, 0, 7) === 'http://') {
							$image = 'https://i2.wp.com/' . substr($image, 7);
						}

						$item['image'] = $image;
					}

					$data[] = $item;
				}
			}

			return $data;
		}

		private function ettoday () {
			global $proxies;

			$data = array();
			$date = date('Y-n-j');

			for ($i = 1; $i <= 10; ++$i) {
				$url = "https://www.ettoday.net/news/news-list-$date-0-$i.htm";

				try {
					$html = file_get_contents($url);
	
					if ($html === false) {
						$ch = curl_init();
						curl_setopt($ch, CURLOPT_URL, $url);
						curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
						curl_setopt($ch, CURLOPT_PROXY, $proxies[rand(0, count($proxies) - 1)]);
						$html = curl_exec($ch);
					}
	
					$doc = phpQuery::newDocument($html);
				} catch (Exception $e) {
					echo "Loading ETtoday Fialed: $url\n";
				}

				foreach ($doc['#all-news-list h3'] as $h3) {
					$h3 = pq($h3);
					$anchor = $h3['a'];

					$data[] = array(
							'title' => trim($anchor->text()),
							'link' => $anchor->attr('href'),
							'category' => $h3['em']->eq(0)->text(),
							'timestamp' => strtotime(date('Y') . '/' . substr($h3['span']->eq(0)->text(), 1, -1)),
							'description' => '',
							'source' => 'ettoday'
						);
				}
			}

			return $data;
		}

		private function cna () {
			$data = array();
			$doc = phpQuery::newDocument(file_get_contents('http://www.cna.com.tw/list/aall-1.aspx'));

			foreach ($doc['.news_list_content tr']->slice(1) as $tr) {
				$tr = pq($tr);
				$anchor = $tr['td > h1 > a']->eq(0);
				$link = $anchor->attr('href');
				$tokens = explode('/', $link);

				$map = array(
						'aipl' => '政治新聞',
						'afe' => '財經新聞',
						'aopl' => '國際新聞',
						'acn' => '兩岸新聞',
						'aedu' => '文教新聞',
						'ait' => '科技新聞',
						'ahel' => '生活新聞',
						'aspt' => '體育新聞',
						'amov' => '影劇新聞',
						'aloc' => '地方新聞',
						'asoc' => '社會新聞'
					);

				$data[] = array(
						'title' => $anchor->text(),
						'link' => 'http://www.cna.com.tw' . $link,
						'category' => $map[$tokens[2]],
						'timestamp' => strtotime(date('Y') . '/' . $tr['h2']->eq(0)->text()),
						'description' => '',
						'source' => 'cna'
					);
			}

			return $data;
		}

		private function chinatimes () {
			$data = array();

			for ($i = 1; $i <= 10; ++$i) {
				$doc = phpQuery::newDocument(file_get_contents('http://www.chinatimes.com/realtimenews?page=' . $i));

				foreach ($doc['.article-list li .col'] as $div) {
					$div = pq($div);
					$anchor = $div['.title > a'];
					$meta = $div['.meta-info'];

					$data[] = array(
							'title' => trim($anchor->text()),
							'link' => 'http://www.chinatimes.com' . $anchor->attr('href'),
							'category' => trim($meta['.category > a']->text()),
							'timestamp' => strtotime($meta['time']->attr('datetime')),
							'description' => '',
							'source' => 'chinatimes'
						);
				}
			}

			return $data;
		}

		private function nownews () {
			$data = array();

			$ch = curl_init();

			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.117 Safari/537.36');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

			curl_setopt($ch, CURLOPT_URL, 'https://www.nownews.com/WirelessFidelity/staticFiles/nownewsIndexpage/indexpageCacheJson');

			$json = curl_exec($ch);

			$items = json_decode($json, true);

			foreach ($items as $item) {
				$data[] = array(
					'link' => $item['link'],
					'timestamp' => strtotime($item['formatStartedAt']),
					'source' => 'nownews',
					'title' => $item['title'],
					'description' => '',
					'image' => $item['mainPhotoUrl']
				);
			}

			return $data;
		}

		private function setn () {
			$data = array();

			for ($i = 1; $i <= 10; ++$i) {
				$doc = phpQuery::newDocument(mb_convert_encoding(file_get_contents('https://www.setn.com/ViewAll.aspx?p=' . $i), 'HTML-ENTITIES', 'UTF-8'));

				foreach ($doc['.NewsList > .col-sm-12 > div'] as $div) {
					$div = pq($div);
					$anchor = $div['.view-li-title > a'];

					$href = $anchor->attr('href');

					if (substr($href, 0, 8) !== 'https://') {
						$href = 'https://www.setn.com' . $href;
					}

					$data[] = array(
							'title' => trim($anchor->text()),
							'link' => $href,
							'category' => $div['.newslabel-tab > a']->text(),
							'timestamp' => strtotime(date('Y') . '/' . $div['time']->text()),
							'description' => '',
							'source' => 'setn'
						);
				}
			}

			return $data;
		}

		private function appledaily() {
			$data = array();

			for ($i = 1; $i <= 10; ++$i) {
				$doc = phpQuery::newDocument(file_get_contents('https://tw.appledaily.com/new/realtime/' . $i));

				foreach ($doc['#maincontent ul.rtddd > li.rtddt > a'] as $anchor) {
					$anchor = pq($anchor);

					$title = $anchor['h1']->text();

					$pos = strrpos($title, '(');

					if ($pos !== false) {
						$title = substr($title, 0, $pos);
					}

					$href = $anchor->attr('href');

					$tokens = explode('/', $href);
					$date = $tokens[count($tokens) - 3];
					$year = intval(substr($date, 0, 4));
					$month = intval(substr($date, 4, 2));
					$day = intval(substr($date, 6, 2));
					$time = $anchor['time']->text();


					$data[] = array(
							'title' => $title,
							'link' => $href,
							'category' => $anchor['h2']->text(),
							'timestamp' => strtotime("$year/$month/$day $time"),
							'description' => '',
							'source' => 'appledaily'
						);
				}
			}

			return $data;
		}
	}

	function combine($chunks) {
		$data = array();
		$map = array();
		$map2 = array();
		$now = time();
		$exceed = $now + 259200;
		$expire = $now - 259200;

		foreach ($chunks as $chunk) {
			foreach ($chunk as $item) {
				$timestamp = $item['timestamp'];

				if ($timestamp > $exceed || $timestamp < $expire) {
					continue;
				}

				$link = $item['link'];

				if (isset($map[$link])) {
					continue;
				}

				$key = $item['source'] . '@' . $item['title'];

				if (isset($map2[$key])) {
					continue;
				}

				$map[$link] = true;
				$map2[$key] = true;
				$data[] = $item;
			}
		}

		return $data;
	}



	$start_time = time();


	$max = 30;
	$count = 0;

	foreach (json_decode(file_get_contents(__DIR__ . '/rssMap.json'), true) as $source => $rsses) {
		foreach ($rsses as $rss) {
			$pid = pcntl_fork();

			if ($pid === 0) {
				(new RssWorker($source, $rss, $count))->run();
				die();
			}

			++$count;

			if ($count % $max === 0) {
				while (pcntl_wait($status) !== -1);
			}
		}
	}

	while (pcntl_wait($status) !== -1);


	$sources = array('chinatimes', 'libertytimes', 'cna', 'ettoday', 'nownews', 'setn', 'appledaily');

	foreach ($sources as $source) {
		$pid = pcntl_fork();

		if ($pid === 0) {
			(new PageWorker($source))->run();
			die();
		}
	}

	while (pcntl_wait($status) !== -1);

	$chunks = array();

	for ($i = 0; $i < $count; ++$i) {
		$chunks[] = json_decode(file_get_contents(__DIR__ . '/temp/r2.' . $i), true);
	}

	foreach ($sources as $source) {
		$chunks[] = json_decode(file_get_contents(__DIR__ . '/temp/p.' . $source), true);
	}

	$files = array_diff(scandir(__DIR__ . '/data'), array('.', '..', '.gitignore'));

	foreach ($files as $file) {
		$chunks[] = json_decode(file_get_contents(__DIR__ . '/data/' . $file), true);
	}

	$data = combine($chunks);

	function cmp($a, $b) {
		return $b['timestamp'] - $a['timestamp'];
	}

	usort($data, 'cmp');

	foreach ($files as $file) {
		unlink(__DIR__ . '/data/' . $file);
	}

	$chunks = array_chunk($data, 100);
	$num = count($chunks);
	$map = array();

	for ($i = 1; $i <= $num; ++$i) {
		file_put_contents(__DIR__ . "/data/$i.json", json_encode($chunks[$i - 1]));
	}


	for ($i = 0; $i < $count; ++$i) {
		$path = __DIR__ . '/temp/r3.' . $i;

		if (file_exists($path)) {
			$map = array_merge($map, json_decode(file_get_contents($path), true));
			unlink($path);
		}
	}

	file_put_contents(__DIR__ . '/cache/redirect.json', $map);


	$spent_time = time() - $start_time;

	echo "Spent: $spent_time sec\n";
?>
