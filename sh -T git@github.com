[33mcommit 2242f2ad1c36d8cfa49b904b9054eee653867b03[m[33m ([m[1;36mHEAD[m[33m -> [m[1;32mmain[m[33m)[m
Author: Domantas Barauskas <Domantas.Barauskas@ibsettle.com>
Date:   Mon Sep 14 14:52:09 2026 +0300

    ADRs 0003 and 0004, transfer concurrency script

 README.md:Zone.Identifier |   4 [31m---[m
 docker-compose.yml        |   2 [32m+[m[31m-[m
 scripts/transfer-race.sh  | 114 [32m++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++[m
 3 files changed, 115 insertions(+), 5 deletions(-)
