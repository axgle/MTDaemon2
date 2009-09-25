task :default do
    sh 'rake --task -s'
end

task :push do
   exec "git push origin master" 
end
namespace :ipcs do
    desc "clean share memory that unused"
    task :sm_clean do
        puts "clean unused share memory"

        data=`ipcs -mp|awk '{print $1,$3,$4}'`
        data.split("\n").select{|d|
            d=~/^\d+.*/
        }.select{|d|
            a,b,c=d.split
            `ps h #{b} #{c}`.size==0
        }.each{|d|
            a,b,c=d.split
            `ipcrm -m #{a}`
            puts "#{a} killed"
        }

        puts `ipcs -m`
    end

    desc "clean semaphores that unused"
    task :se_clean=>:sm_clean do
        puts "clean unused semaphores"
        m=`ipcs -m|grep 0x|awk '{print $1}'`.split("\n")
        s=`ipcs -s|grep 0x|awk '{print $1}'`.split("\n")
        d=s-m
        d.each do |k|
            `ipcrm -S #{k}`
            puts "key:#{k} removed"
        end
        puts `ipcs -s`
    end

    #desc "clean all semaphores"
    task :se_clean_all do
        puts `ipcs -s`

        data=`ipcs -s|awk '{print $2}'`
        data.split("\n").select{|d|
            d=~/^\d+.*/
        }.each{|d|`ipcrm -s #{d}`}

        puts `ipcs -s`
    end
end
