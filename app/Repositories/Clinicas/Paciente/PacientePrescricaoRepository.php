<?php

namespace App\Repositories\Clinicas\Paciente;

use Illuminate\Support\Facades\DB;
use App\Repositories\BaseRepository;
use App\Helpers\Functions;

class PacientePrescricaoRepository extends BaseRepository {

    public function getAll($idDominio, Array $dadosFiltro = null, $page = null, $perPage = null) {

        $sqlOrdem = 'ORDER BY B.data_consulta';

        if (is_array($idDominio)) {
            $sql = 'A.identificador IN(' . implode(',', $idDominio) . ")";
        } else {
            $sql = " A.identificador = $idDominio ";
        }
        if (!empty($dadosFiltro['pacienteId'])) {
            $sql .= "AND (B.pacientes_id = '{$dadosFiltro['pacienteId']}' OR importado_paciente_id = '{$dadosFiltro['pacienteId']}') ";
        }


        if (isset($dadosFiltro['data']) and ! empty($dadosFiltro['data'])) {

            if (isset($dadosFiltro['dataFim']) and ! empty($dadosFiltro['dataFim'])) {
                $sql .= " AND B.data_consulta >='{$dadosFiltro['data']}' AND B.data_consulta <= '{$dadosFiltro['dataFim']}'";
            } else {
                $sql .= " AND B.data_consulta ='{$dadosFiltro['data']}' ";
            }
        }

        if (isset($dadosFiltro['consultaId']) and ! empty($dadosFiltro['consultaId'])) {
            $sql .= " AND A.consultas_id  = {$dadosFiltro['consultaId']}";
        }

        if (isset($dadosFiltro['campoOrdenacao']) and ! empty($dadosFiltro['campoOrdenacao'])) {
            $sqlOrdem = " ORDER BY {$dadosFiltro['campoOrdenacao']} ";
            if (isset($dadosFiltro['tipoOrdenacao']) and ! empty($dadosFiltro['tipoOrdenacao'])) {
                $sqlOrdem .= $dadosFiltro['tipoOrdenacao'];
            }
        }


        $camposSql = "A.*,B.data_consulta, B.hora_consulta,AES_DECRYPT(F.nome_cript, '$this->ENC_CODE') as nome, B.pacientes_id, B.id as idConsulta,
            (SELECT COUNT(*) FROM consultas_prescricao_item WHERE consulta_prescricao_id= A.id AND especial = 1) AS qntEspecial,
                (SELECT  COUNT(*) FROM consultas_prescricao_item WHERE consulta_prescricao_id= A.id AND especial = 0) AS qntNormal";
        $from = "FROM consultas_prescricao as A 
                                            LEFT JOIN consultas as B
                                            ON A.consulta_id = B.id
                                            LEFT JOIN doutores as F
                                            ON F.id = B.doutores_id
                                            WHERE  $sql ORDER BY  B.data_consulta DESC";

        if ($page == null and $perPage == null) {
            $qr = $this->connClinicas()->select("SELECT $camposSql $from");
            return $qr;
        } else {
            $qr = $this->paginacao($camposSql, $from, 'clinicas', $page, $perPage, true);
            return $qr;
        }
    }

    public function getByConsultaId($idDominio, $consultaId, $dadosFiltro = null) {
        $dadosFiltro['consultaId'] = $consultaId;
        $qr = $this->getAll($idDominio, null, $dadosFiltro);
        if (count($qr) > 0) {
            return $qr;
        } else {
            return false;
        }
    }

    public function store($idDominio, $pacienteId, Array $dadosInsert) {
        
    }

    public function getItensByIdPrescricao($idDominio, $idPrescricao) {



        $qr = $this->connClinicas()->select("SELECT A.*,C.med_medida_nome,C.med_media_sigla, D.med_via_nome,B.med_nome   FROM  consultas_prescricao_item as A 
                                        LEFT JOIN medicamentos as B
                                        ON A.idMedicamento = B.idMedicamento
                                        LEFT JOIN medicamentos_medidas as C
                                        ON C.idMedicamentoMedida = A.medicamentos_medidas_id
                                        LEFT JOIN medicamentos_vias as D
                                        ON D.idMedicamentoVia = B.medicamento_via_id
                                        WHERE A.consulta_prescricao_id =$idPrescricao");

        return $qr;
    }

}
