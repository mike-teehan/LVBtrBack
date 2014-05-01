#!/bin/bash 

export remote_host=$1;
export remote_image=$2;
export local_image=$3;

perl -'MDigest::MD5 md5' -ne 'BEGIN{$/=\1024}; print md5($_);' $local_image | lzop -c | ssh $remote_host "lzop -dc | perl -'MDigest::MD5 md5' -ne 'BEGIN{\$/=\1024}; \$b=md5(\$_); read STDIN,\$a,16;if (\$a eq \$b) {print \"s\"} else {print \"c\" . \$_}' $remote_image " | perl -ne 'BEGIN{$/=\1}; if ($_ eq "s") { $s++; } else { if ($s) { seek STDOUT,$s*1024,1; $s=0; }; read ARGV,$buf,1024; print $buf; }' 1<> $local_image


