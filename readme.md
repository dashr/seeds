#Seeds

Seeds provides an RSS aggregator, from multiple blog sources and post styles, with a focus on finding and displaying Art images. While many great RSS feed readers exist, we needed a compact drop-in lib that worked easily. It loads recent blog entries, and is not meant to be used as a permament archive.

It is still undergoing developement, testing and refactoring before it goes live. It is created and maintained by [jose d lopez](http://tumis.com) of house TUMIS for use by the Just Seeds art collective. [JustSeeds.org](http://JustSeeds.org)


## Quick Start
#####Create a MySQL database with these 2 tables.

```
CREATE TABLE farmers (
  farmer varchar(255) NOT NULL,
  title varchar(255) NOT NULL,
  description text NOT NULL,
  frequency tinyint(3) NOT NULL,
  weight tinyint(3) NOT NULL,
  PRIMARY KEY (farmer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE seeds (
  guid varchar(255) NOT NULL,
  farmer varchar(255) NOT NULL,
  link varchar(255) NOT NULL,
  title varchar(255) NOT NULL,
  description text NOT NULL,
  image varchar(255) DEFAULT NULL,
  posted int(10) unsigned NOT NULL,
  last_retrieved timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (guid),
  KEY farmer (farmer),
  KEY posted (posted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

#####Enter the Database credentials in app/lib.php

```
/**
 * MySQL database info
 *
 */
$db = array
(
	'host' => 'localhost',
	'name' => 'seeds_aggregator',
	'user' => 'root',
	'pass' => 'root'
);
```

#####Provide a list of RSS feeds

```
/*
 * Direct URL to RSS/Atom (xml) feeds of Farmers
 *
 */
$farmers = array
(
	'http://interferencearchive.org/rss',
	'http://nicolaslampert.wordpress.com/feed/',
	'http://dignidadrebelde.com/blog/rss/user/2',
	'http://favianna.typepad.com/faviannacom_art_activism/rss.xml',
	'http://mulchthief.blogspot.com/feeds/posts/default?alt=rss',
	'http://slifer-freeman.tumblr.com/rss'
);
```


#####Schedule a cronjob at a decent interval
Based on the number of blogs, the blog provider, the number of blog posts, and the speed of your server, the script can take 1-5 minutes to run. Determining the primary post image can have a high latency. Results are not cached as updates can occur. 

```
#	cronjob: check/update every 3 hours 
#   0 */3 * * * /usr/local/bin/php ~/app/cron.php >> /dev/null
```



## Resources
Seeds uses a few open source libraries on the frontend (all included, license is respective of their owners):

* JQuery Live Query
* JQuery Masonry
* JQuery Time Ago
* Twitter Bootstrap


## Open Source License

Code is released under the MIT public license. Play at your own risk.
