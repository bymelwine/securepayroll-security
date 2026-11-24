<?php

declare(strict_types=1);
namespace App\Auth;
use PDO;
final class IAMRepository
{
    public function __construct(private PDO $db) {}
    public function overview(int $actor,int $page): array
    {
        $sets=$this->call('CALL iam_overview(?,?)',[$actor,$page]);
        return ['accounts'=>$sets[0]??[],'roles'=>$sets[1]??[],'assignments'=>$sets[2]??[]];
    }
    public function grant(int $account,string $role,string $reason,int $actor): void
    { $this->call('CALL iam_grant(?,?,?,?)',[$account,$role,$reason,$actor]); }
    public function revoke(int $assignment,string $reason,int $actor): void
    { $this->call('CALL iam_revoke(?,?,?)',[$assignment,$reason,$actor]); }
    private function call(string $sql,array $parameters): array
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Transaction already active.'); }
        $query=$this->db->prepare($sql);$failure=null;
        try {
            $query->execute($parameters);$sets=[];
            do { if ($query->columnCount()>0) { $sets[]=$query->fetchAll(); } } while ($query->nextRowset());
            return $sets;
        } catch (\Throwable $error) { $failure=$error;throw $error; }
        finally { try { $query->closeCursor(); } catch (\Throwable $cleanup) { if ($failure===null) { throw $cleanup; } } }
    }
}
