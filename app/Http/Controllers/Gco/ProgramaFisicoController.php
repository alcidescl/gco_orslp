<?php

namespace App\Http\Controllers\Gco;

use App\Models\Amplazo;
use App\Models\Avance;
use App\Models\Avdetail;
use App\Models\Casosampliacion;
use App\Models\Proyecto;
use App\Models\Presupuesto;
use App\Models\Listpresupuesto;
use App\Models\Itempresupuesto;
use App\Models\Tipopresupuesto;
use App\Models\Equiprof;
use App\Models\Uejecutora;
use App\Models\Partida;
use App\Models\Resumenavc;
use App\Models\Progfisica;
use App\Models\Postor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Exception;
use Excel;
use File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Protection;

use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Chart\Axis;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\RichText\Run;

use PhpOffice\PhpSpreadsheet\Worksheet\HeaderFooter;
use PhpOffice\PhpSpreadsheet\Worksheet\HeaderFooterDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\SheetView;

class ProgramaFisicoController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $pyAccess = Auth::user()->tusProject;

        if($pyAccess == 0){
            $pys = Proyecto::where('pryInvalidate',false)->get();
        }
        else{
            $pys = Proyecto::where('pryInvalidate',false)
                    ->whereIn('pryId',explode(',',$pyAccess))
                    ->get();
        }

        $view = view('gestion.panel_progfisica', compact('pys'));

        return $view;
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {

        $pyId = $request->pyId;
        $prId = $request->prId;

        $resumen = Presupuesto::with('items')->where('preProject',$pyId)->where('preId',$prId)->get();

        $cronograma = array();

        $pry = Proyecto::find($pyId);
        $eje = Proyecto::find($pyId)->ejecutor()->get();
        $fecIni = is_null($eje[0]->ejeStartDate) ? null : Carbon::parse($eje[0]->ejeStartDate);
        $fecFin = is_null($eje[0]->ejeEndDate) ? null : Carbon::parse($eje[0]->ejeEndDate);
        $plazoMeses = $eje[0]->ejeMonthTerm;
        $plazoDias = $eje[0]->ejeDaysTerm;
        $fechaValorizacion = $fecIni->copy();
        $i = 0;

        if($plazoMeses == null){
            $plazoMeses = round($plazoDias / 30);
        }

        while($plazoMeses > 0){

            $i++;

            if($plazoMeses == 1){
                // 24/02/2018 - 01/02/2018
                //echo $fechaValorizacion . ' / ';
                //echo $fecFin->diffInDays($fechaValorizacion);
                if($fecFin > $fechaValorizacion){
                    if($fecFin->diffInDays($fechaValorizacion) < $fechaValorizacion->daysInMonth){
                        $valorizacion = array('val' => $i, 'fechai'=>$fechaValorizacion->copy(),'fechaf' => $fecFin);
                        array_push($cronograma, $valorizacion);
                    }
                }
                else if(is_null($fecFin)){
                    $valorizacion = array('val' => $i, 'fechai' => $fechaValorizacion->copy(), 'fechaf' => $fechaValorizacion->endOfMonth());
                    array_push($cronograma, $valorizacion);
                }
            }
            else{
                $valorizacion = array('val' => $i, 'fechai' => $fechaValorizacion->copy(), 'fechaf' => $fechaValorizacion->endOfMonth());
                array_push($cronograma,$valorizacion);
            }

            $fechaValorizacion = $fechaValorizacion->copy()->addDays(1);

            $plazoMeses--;
        }

        array_push($cronograma, array('val' => 'TOTAL','fechai' => null, 'fechaf' => null));
        
        $view = view('formularios.nuevo_programacion',compact('pry','cronograma','resumen','eje'));

        return $view;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        try{

            $exception = DB::transaction(function() use($request){

                $pyId = $request->hnpyId;
                $prId = $request->hnprId;
                $keyPto = explode('-', $request->hnpyResumenPto);
                $ptoId = $keyPto[0];

                $pry = Proyecto::find($pyId);
                $eje = Proyecto::find($pyId)->ejecutor()->get();
                $pry->pryBaseBudget = $ptoId;
                $pry->save();

                foreach ($request->nvalNumber as $i => $val) {
                    
                    $cronograma = new Progfisica();
                    $cronograma->prgProject = $pyId;
                    $cronograma->prgBudget = $prId;
                    $cronograma->prgExecutor = $eje[0]->ejeId;
                    $cronograma->prgNumberVal = $val;
                    $cronograma->prgStartPeriod = $request->nvalPeriodi[$i];
                    $cronograma->prgEndPeriod = $request->nvalPeriodf[$i];
                    $cronograma->prgPeriodo = $request->nvalPeriodf[$i];
                    $cronograma->prgMount = floatval(str_replace(',', '', $request->nvalMount[$i]));
                    $cronograma->prgPercent = floatval($request->nvalPrcnt[$i]) / 100;
                    $cronograma->prgAggregate = floatval($request->nvalAggrt[$i]) / 100;
                    $cronograma->save();

                    unset($cronograma);

                }
            });

            if(is_null($exception)){
                $msgId = 200;
                $msg = 'Cronograma registrado correctamente';
                $url = url('presupuesto/programacion');
            }
            else{
                throw new Exception($exception);
            }

        }catch(Exception $e){

            $msg = 'Error: ' . $e->getMessage() . ' -- ' . $e->getFile() . ' - ' . $e->getLine() . " \n";
            $msgId = 500;
            $url = '';

        }
        
        return response()->json(compact('msg','msgId','url'));
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $curva)
    {
        $pyId = $request->pyId;
        $prId = $request->prId;
        $cronograma = Progfisica::where('prgProject',$pyId)
                        ->where('prgBudget',$prId);

        if($curva == '1'){

            $cronograma = $cronograma->where('prgNumberVal','!=',null)->orderBy('prgStartPeriod','asc')->get();

            $chartData = '';
            $labels = $cronograma->pluck('prgPeriodo');

            $programado = $cronograma->map(function($item,$key){
                return round($item->prgAggregate * 100,2);
            });

            $ejecutado = $cronograma->map(function($item,$key){
                return round($item->prgAggregateExec * 100,2);
            });

            /*$chartData .= "{ 
                labels: $labels, 
                datasets: [{
                    label: 'Cantidad Programada',
                    fill: false,
                    backgroundColor: 'rgba(255,99,132,0.5)',
                    borderColor: 'rgb(255,99,132)',
                    data:$programado
                },{
                    label: 'Cantidad Ejecutada',
                    fill: false,
                    backgroundColor: 'rgba(54, 162, 235,0.5)',
                    borderColor: 'rgb(54, 162, 235)',
                    spanGaps: true,
                    data:$ejecutado
                }]
            }";*/

            $chartData .= "[{
                    name: 'Cantidad Programada',
                    data:$programado
                },{
                    name: 'Cantidad Ejecutada',
                    data:$ejecutado
                }]";

            $view = view('presentacion.slide_curvas', compact('labels','cronograma','chartData'));
        }
        else{

            $cronograma = $cronograma->orderBy('prgStartPeriod','asc')->get();
            $casos = Casosampliacion::all();

            if($cronograma->isEmpty()){
                $view = $this->create($request);
            }
            else{
                $pry = Proyecto::find($pyId);
                //$resumen = Presupuesto::where('preId',$prId)->where('preProject',$pyId)->get();
                $resumen = Itempresupuesto::where('iprBudget',$prId)->get();
                $eje = Proyecto::find($pyId)->ejecutor()->get();
                $amp = Amplazo::select('*')
                            ->join('gcocasoampliacion','camId','=','ampCaso')
                            ->where('ampProject',$pyId)
                            ->where('ampBudget',$prId)
                            ->get();

                $view = view('formularios.editar_programacion', compact('cronograma','pry','eje','resumen','casos','amp'));

            }
        }

        return $view;
    }

    public function show_con_morris(Request $request, $curva)
    {
        $pyId = $request->pyId;
        $cronograma = Progfisica::where('prgProject',$pyId)->orderBy('prgNumberVal','asc')->get();

        if($curva == '1'){
            $data = '';

            foreach ($cronograma as $i => $crono) {

                $programado = $crono->prgAggregate * 100;
                $ejecutado = $crono->prgAggregateExec;
                if(is_null($ejecutado)){
                    $ejecutado = 0;
                }
                else{
                    $ejecutado = $ejecutado * 100;
                }

                $data .= "{ periodo: '" . $crono->prgPeriodo . "', programado: " . $programado . ", ejecutado: " . $ejecutado . "}, ";


            }
            $data = substr($data, 0, -2);

            $view = view('presentacion.slide_curvas', compact('cronograma','data'));
        }
        else{

            if($cronograma->isEmpty()){
                $view = $this->create($request);
            }
            else{
                $pry = Proyecto::find($pyId);
                $resumen = Presupuesto::where('preProject',$pyId)->get();

                $view = view('formularios.editar_programacion', compact('cronograma','pry','resumen'));

            }
        }

        return $view;
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        $pyId = $request->hnpyId;
        $ptId = $request->hnptId;

        try{

            $exception = DB::transaction(function() use($request){

                $pyId = $request->hnpyId;
                $ptId = $request->hnptId;

                $count = 0;
                
                foreach ($request->hnvalId as $i => $val) {
                    
                    $cronograma = Progfisica::find($val);

                    if($request->nvalMount[$i] == 0){
                        $valNro = null;
                        $count++;
                    }
                    else{
                        $valNro = $cronograma->prgNumberVal - $count;
                    }
                    
                    if($val != 0){

                        $cronograma->prgNumberVal = $valNro;
                        $cronograma->prgStartPeriod = $request->nvalPeriodi[$i];
                        $cronograma->prgEndPeriod = $request->nvalPeriodf[$i];
                        $cronograma->prgPeriodo = $request->nvalMount[$i] == 0 ? 'SV': $request->nvalPeriodf[$i];
                        $cronograma->prgMount = floatval(str_replace(',', '', $request->nvalMount[$i]));
                        $cronograma->prgPercent = floatval($request->nvalPrcnt[$i]) / 100;
                        $cronograma->prgAggregate = floatval($request->nvalAggrt[$i]) / 100;
                        $cronograma->prgEditNote = $request->nvalNote[$i];
                        $cronograma->save();

                        $lastExe = $cronograma->prgExecutor;

                        unset($cronograma);

                    }
                    else{
                        $periodo = new Progfisica();
                        $periodo->prgProject = $pyId;
                        $periodo->prgBudget = $ptId;
                        $periodo->prgExecutor = $lastExe;
                        $periodo->prgNumberVal = $valNro; // $request->nvalNumber[$i];
                        $periodo->prgStartPeriod = $request->nvalPeriodi[$i];
                        $periodo->prgEndPeriod = $request->nvalPeriodf[$i];
                        $periodo->prgPeriodo = $request->nvalMount[$i] == 0 ? 'SV': $request->nvalPeriodf[$i];
                        $periodo->prgMount = floatval(str_replace(',', '', $request->nvalMount[$i]));
                        $periodo->prgPercent = floatval($request->nvalPrcnt[$i]) / 100;
                        $periodo->prgAggregate = floatval($request->nvalAggrt[$i]) / 100;
                        $periodo->prgEditNote = $request->nvalNote[$i];
                        $periodo->save();

                        unset($periodo);
                    }

                }

            });

            if(is_null($exception)){
                $msg = 'Cronograma calendarizado actualizado correctamente';
                $msgId = 200;
                $url = url('ver/programacion/0');
            }
            else{
                throw new Exception($exception);
            }

        }catch(Exception $e){
            $msg = "Error: " . $e->getMessage();
            $msgId = 500;
            $url = '';
        }

        return response()->json(compact('msg','msgId','url','pyId','ptId'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    public function verify(Request $request)
    {
        $pyId = $request->pyId;
        $prgFisico = Progfisica::where('prgProject',$pyId)->count();
        $msgId = 0;

        if($prgFisico == 0){
            $msgId = 0;
        }
        else{
            $msgId = 1;
        }

        return $msgId;
    }

    public function indexCurva()
    {
        $pyAccess = Auth::user()->tusProject;

        if($pyAccess == 0){
            $pys = Proyecto::where('pryInvalidate',false)->get();
        }
        else{
            $pys = Proyecto::where('pryInvalidate',false)
                    ->whereIn('pryId',explode(',',$pyAccess))
                    ->get();
        }

        $view = view('gestion.panel_curvas', compact('pys'));

        return $view;
    }

    public function generateSheet(Request $request)
    {
        $spreadsheet = new Spreadsheet();

        $spreadsheet->getProperties()
           ->setCreator('Symva')
           ->setTitle('Curva S - Oficina Regional de Supervisión y Liquidación de Proyectos')
           ->setLastModifiedBy('Symva')
           ->setDescription('Archivo excel del avance fisico de la obra')
           ->setSubject('Avance Fisico')
           ->setCategory('SIGCO');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('CurvaS');

        $pyId = $request->pyId;
        $prId = $request->prId;

        $cronograma = Progfisica::where('prgProject',$pyId)
                        ->where('prgBudget',$prId)
                        ->where('prgNumberVal','!=',null)
                        ->orderBy('prgNumberVal','asc')
                        ->get();

        $pry = Proyecto::with('ejecutor')->where('pryId',$pyId)->get();
        $pst = Postor::with('individualData')->where('pstId',$pry[0]->ejecutor->ejePostor)->get();
        $prf = Equiprof::with('individualData')
                ->where('prfUejecutora',$pry[0]->pryExeUnit)
                ->where('prfJob','SUPERVISOR')
                ->orWhere('prfJob','INSPECTOR')
                ->get();
        //$preB = Presupuesto::where('preProject',$pyId)
        $preB = Itempresupuesto::where('iprBudget',$prId)
                ->where('iprId',$pry[0]->pryBaseBudget)
                ->get();
        $preS = Itempresupuesto::where('iprBudget',$prId)
                ->where('iprCodeItem','ST')
                ->get();
        $preT = Itempresupuesto::where('iprBudget',$prId)
                ->where('iprCodeItem', 'PT')
                ->get();

        $nval = $cronograma->count();

        $data = array(['Nro','Periodo','Programado','','','Ejecutado']);
        $header = array('','','Monto','Avance','Acumulado','Monto','Avance','Acumulado');

        array_push($data, $header);

        foreach ($cronograma as $i => $crono) {
            
            $row = [
                    $crono->prgNumberVal,
                    Carbon::parse($crono->prgPeriodo)->format('d-m-y'), 
                    $crono->prgMount,
                    $crono->prgPercent,
                    $crono->prgAggregate,
                    $crono->prgMountExec,
                    $crono->prgPercentExec,
                    $crono->prgAggregateExec
                ];

            array_push($data, $row);

        }

        /* Titulo y Subtitulo */

        $sheet->getColumnDimension('A')->setWidth(2);
        $sheet->getColumnDimension('B')->setWidth(25);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(10);
        $sheet->getColumnDimension('J')->setWidth(2);

        $titleStyle = [
            'font' => [
                'name' => 'Arial Narrow',
                'bold' => true,
                'size' => 12,
                'underline' => Font::UNDERLINE_SINGLE
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
        ];

        $subtitleStyle = [
            'font' => [
                'name' => 'Arial Narrow',
                'bold' => true,
                'size' => 11
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
        ];

        $sheet->mergeCells('B1:I1');
        $sheet->setCellValue('B1', 'AVANCE DEL CALENDARIO PROGRAMADO VS EJECUTADO');
        $sheet->getStyle('B1')->applyFromArray($titleStyle);
        $sheet->mergeCells('B2:I2');
        $sheet->setCellValue('B2', 'VALORIZACIÓN NRO: ');
        $sheet->getStyle('B2')->applyFromArray($subtitleStyle);


        /* Information - Información General */

        $bodyInfo1 = 'B4:B14';
        $bodyInfo2 = 'C4:I14';

        $bodyInfoStyle1 = [
            'font' => [
                'name' => 'Arial Narrow',
                'bold' => true,
                'size' => 9
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
        ];

        $bodyInfoStyle2 = [
            'font' => [
                'name' => 'Arial Narrow',
                'size' => 9
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
        ];

        for($row = 4; $row <= 14; $row++){
            $sheet->mergeCells('C' . $row . ':I' . $row);
            $sheet->getStyle('C' . $row . ':I' . $row)->getAlignment()->setWrapText(true);
        }

        $sheet->setCellValue('B4', 'OBRA');
        $sheet->setCellValue('C4', ': ' . $pry[0]->pryDenomination);
        $sheet->setCellValue('B5', 'UBICACIÓN');
        $sheet->setCellValue('B6', 'ENTIDAD');
        $sheet->setCellValue('C6', ': GOBIERNO REGIONAL');
        $sheet->setCellValue('B7', 'CONTRATISTA');
        $sheet->setCellValue('C7', ': ' . $pst[0]->individualData->prjBusiName);
        $sheet->setCellValue('B8', 'SUPERVISIÓN');
        $sheet->setCellValue('C8', ': ' . $prf[0]->individualData->perFullName);
        $sheet->setCellValue('B9', 'PRESUPUESTO BASE');
        $sheet->setCellValue('C9', ": S/ " . number_format($preB[0]->iprItemGeneralMount,2,'.',','));
        $sheet->setCellValue('B10', 'PRESUPUESTO CONTRATADO');
        $sheet->setCellValue('C10', ": S/" . number_format($preS[0]->iprItemGeneralMount,2,'.',','));
        $sheet->setCellValue('B11', 'PRESUPUESTO CONTRATADO');
        $sheet->setCellValue('C11', ": S/" . number_format($preT[0]->iprItemGeneralMount,2,'.',','));
        $sheet->setCellValue('B12', 'PLAZO DE EJECUCIÓN');
        $sheet->setCellValue('C12', ': ' . $pry[0]->ejecutor->ejeDaysTerm . ' DÍAS CALENDARIOS');
        $sheet->setCellValue('B13', 'FECHA DE INICIO');
        $sheet->setCellValue('C13', ': ' . $pry[0]->ejecutor->ejeStartDate);
        $sheet->setCellValue('B14', 'FECHA DE TÉRMINO');
        $sheet->setCellValue('C14', ': ' . $pry[0]->ejecutor->ejeEndDate);

        $sheet->getStyle($bodyInfo1)->applyFromArray($bodyInfoStyle1);
        $sheet->getStyle($bodyInfo2)->applyFromArray($bodyInfoStyle2);

        /* Table- table - content shedule - cronograma */

        //\PhpOffice\PhpSpreadsheet\Cell\Cell::setValueBinder( new \PhpOffice\PhpSpreadsheet\Cell\AdvancedValueBinder() );

        $rowHeader = 17;
        $rowLast = $rowHeader + $nval;

        $sheet->fromArray($data, ' ', 'B' . ($rowHeader - 1));

        $header = 'B' . ($rowHeader - 1) . ':I' . $rowHeader;
        $style = [
            'font' => [
                'bold' => true,
                'name' => 'Arial Narrow',
                'size' => 9
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => [
                'outline' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => '00343D46'],
                ],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'argb' => '00CCFFCC',
                ]
            ],
        ];

        $sheet->getStyle($header)->applyFromArray($style);

        $body = 'B' . ($rowHeader + 1) . ':I' . $rowLast;
        $bodyStyle = [
            'font' => [
                'name' => 'Arial Narrow',
                'size' => 9
            ],
            'borders' => [
                'bottom' => ['borderStyle' => Border::BORDER_THIN],
                'right' => ['borderStyle' => Border::BORDER_THIN],
                'top' => ['borderStyle' => Border::BORDER_THIN],
                'left' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ];
        $sheet->getStyle($body)->applyFromArray($bodyStyle);

        $titleHeaderStyle = [
            'borders' => [
                'bottom' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ];
        $sheet->getStyle('D' . ($rowHeader - 1) . ':F' . ($rowHeader - 1))->applyFromArray($titleHeaderStyle);
        $sheet->getStyle('G' . ($rowHeader - 1) . ':I' . ($rowHeader - 1))->applyFromArray($titleHeaderStyle);

        for ($col = ord('B'); $col <= ord('I'); $col++)
        {
            $sheet->getColumnDimension(chr($col))->setAutoSize(true);
        }

        //$sheet->getStyle('C' . ($rowHeader + 1 ) . ':C100')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_DDMMYYYY);
        $sheet->getStyle('D' . ($rowHeader + 1 ) . ':D100')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        $sheet->getStyle('E' . ($rowHeader + 1 ) . ':E100')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_PERCENTAGE_00);
        $sheet->getStyle('F' . ($rowHeader + 1 ) . ':F100')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_PERCENTAGE_00);
        $sheet->getStyle('G' . ($rowHeader + 1 ) . ':G100')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        $sheet->getStyle('H' . ($rowHeader + 1 ) . ':H100')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_PERCENTAGE_00);
        $sheet->getStyle('I' . ($rowHeader + 1 ) . ':I100')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_PERCENTAGE_00);

        /*$drawing = new Drawing();
        $drawing->setName('GORE');
        $drawing->setPath(public_path()."/img/gore_sede.jpg");
        $drawing->setHeight(36);
        $drawing->setCoordinates('K5');
        $drawing->setWorksheet($sheet);*/

        /* Gráfico - Chart- Picture - Graph for EXCEL */

        $axis = new Axis();
        $axis->setAxisOptionsProperties('nextTo', null, null, null, null, null, null, 1);

        $lblSerie1 = 'CurvaS!$D$' . ($rowHeader - 1);
        $lblSerie2 = 'CurvaS!$G$' . ($rowHeader - 1);
        $rngAxisX = 'CurvaS!$C$' . ($rowHeader + 1) . ':$C$' . $rowLast;
        $rngSerie1 = 'CurvaS!$F$' . ($rowHeader + 1) . ':$F$' . $rowLast;
        $rngSerie2 = 'CurvaS!$I$' . ($rowHeader + 1) . ':$I$' . $rowLast;


        $dsl=array(
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $lblSerie1, NULL, 1),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $lblSerie2, NULL, 1),
            );

        $xal=array(
                new DataSeriesValues('String', $rngAxisX, NULL, $nval),
            );

        $dsv=array(
                new DataSeriesValues('Number', $rngSerie1, NULL, $nval),
                new DataSeriesValues('Number', $rngSerie2, NULL, $nval),
            );

        $ds=new DataSeries(
                    DataSeries::TYPE_LINECHART,
                    DataSeries::GROUPING_STANDARD,
                    range(0, count($dsv)-1),
                    $dsl,
                    $xal,
                    $dsv
                );

        $pa=new PlotArea(NULL, array($ds));
        $legend=new Legend(Legend::POSITION_TOP, NULL, false);
        $title=new Title('AVANCE PROGRAMADO VS EJECUTADO');

        $yAxisLabel = new Title('% Ejecutado');
        $xAxisLabel = new Title('Periodo');

        $chart= new Chart(
                    'chart1',
                    null,
                    $legend,
                    $pa,
                    true,
                    0,
                    null,//$xAxisLabel, 
                    null,//$yAxisLabel,
                    $axis
                    );

        $tlPosition = 'B' . ($rowLast + 2);
        $brPosition = 'J' . ($rowLast + 20);

        $chart->setTopLeftPosition($tlPosition);
        $chart->setBottomRightPosition($brPosition);
        $sheet->addChart($chart);

        /* Header and Footer */

        $sheet->getHeaderFooter()->setOddHeader('&L&G&C&H&BOFICINA REGIONAL DE SUPERVISIÓN Y LIQUIDACIÓN DE PROYECTOS');
        $sheet->getHeaderFooter()->setOddFooter('&L' . $spreadsheet->getProperties()->getTitle() . '&RPágina &P de &N');

        $drawing = new HeaderFooterDrawing();
        $drawing->setName('GORE logo');
        $drawing->setPath(public_path() . '/img/region64x64.png');
        $drawing->setHeight(42);
        $spreadsheet->getActiveSheet()
            ->getHeaderFooter()
            ->addImage($drawing, HeaderFooter::IMAGE_HEADER_LEFT);

        /* Modo de vista de la hoja excel */

        $sheet->getSheetView()->setView(SheetView::SHEETVIEW_PAGE_BREAK_PREVIEW);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setScale(99);

        $writer = new Xlsx($spreadsheet);
        $writer->setIncludeCharts(true);
        header('Content-type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="avance-fisico.xlsx"');
        $writer->save('php://output');
    }
    /*
     * Función para adjuntar archivo a la ampliación de plazo registrada
     */
    public function uploadFileProgramacion(Request $request)
    {
        try{
            $pyId = $request->hnatchPry;
            $apId = $request->hnatchAmp;
            $url = url('ver/programacion/0');

            if($request->hasFile('natchFile')){

                $file = $request->file('natchFile');
                $path_parts = pathinfo($_FILES['natchFile']['name']);
                $filename = $path_parts['filename'];
                $fileext = $path_parts['extension'];

                $path_saved = $file->storeAs('docsprogramacion/' . $apId, $filename . '_' . time() . '.' . $fileext);

                if(Storage::disk('public')->exists($path_saved)){
                    $ampliacion = Amplazo::find($apId);
                    $ampliacion->ampPathFile = $path_saved;
                    //$ampliacion->save();

                    if($ampliacion->save()){
                        $msg = 'Archivo adjunto subido y registrado correctamente.';
                        $msgId = 200;
                        $ptId = $ampliacion->ampBudget;
                    }
                    else{
                        throw new Exception("Error al registrar la ruta del archivo en la base de datos. Revise su conexión.");
                    }

                }
                else{
                    throw new Exception("El archivo no se ha almacenado correctamente.");
                }
            }
            else{
                throw new Exception("No se ha adjuntado ningun archivo");
            }
        }
        catch(Exception $e){
            $msg = 'Error: ' . $e->getMessage() . ' -Archivo- ' . $e->getFile() . ' -Linea- ' . $e->getLine() . " \n";
            $msgId = 500;
            $url= '';
        }

        return response()->json(compact('msg','msgId','url','pyId','ptId'));
    }

    public function postEditStatusPaid(Request $request)
    {
        $prgId = $request->pk;
        $statusValue = $request->value;

        $prg = Progfisica::find($prgId);
        $prg->prgPaid = $statusValue == 'A' ? true : false;
        
        if($prg->save()){
            $success = true;
            $msg = 'Estado cambiado correctamente';
        }
        else{
            $success = false;
            $msg = 'Error, refresque la página e intente de nuevo';
        }

        return response()->json(compact('success','msg'));
    }

    public function postEditStatusExec(Request $request)
    {
        $prgId = $request->pk;
        $statusValue = $request->value;

        $prg = Progfisica::find($prgId);
        $prg->prgStatus = $statusValue;
        
        if($prg->save()){
            $success = true;
            $msg = 'Estado cambiado correctamente';
        }
        else{
            $success = false;
            $msg = 'Error, refresque la página e intente de nuevo';
        }

        return response()->json(compact('success','msg'));
    }

    public function storeTermExtension(Request $request)
    {
        try{
            $prg = Progfisica::find($request->nampPrgfisica);
            $pyId = $prg->prgProject;
            $ptId = $prg->prgBudget;

            $exception = DB::transaction(function() use($request, $prg){

                $amp = new Amplazo();
                $amp->ampProject = $prg->prgProject;
                $amp->ampBudget = $prg->prgBudget;
                $amp->ampSchedulePeriod = $prg->prgId;
                $amp->ampCaso = $request->nampCaso;
                $amp->ampNote = $request->nampNote;
                $amp->ampStartStay = $request->nampStart;
                $amp->ampEndStay = $request->nampEnd;
                $amp->ampDaysTerm = $request->nampDays;
                $amp->ampEndExe = $request->nampEndexec;

                if($request->hasFile('nampFile')){

                    $file = $request->file('nampFile');
                    $path_parts = pathinfo($_FILES['nampFile']['name']);
                    $filename = $path_parts['filename'];
                    $fileext = $path_parts['extension'];
    
                    $path_saved = $file->storeAs('docsprogramacion/' . $prg->prgId, $filename . '_' . time() . '.' . $fileext);
    
                    if(Storage::disk('public')->exists($path_saved)){
                        $amp->ampPathFile = $path_saved;
                    }
                    else{
                        throw new Exception("El archivo no se ha almacenado correctamente.");
                    }
                }

                $amp->save();

                /* Modificando el cronograma actual existente */
                
                $currLastVal = DB::table('gcoprogfisica as a')
                                ->select('a.*')
                                ->leftJoin(
                                    DB::raw('gcoprogfisica as b'), function($join){
                                        $join->on('a.prgProject','=','b.prgProject');
                                        $join->on('a.prgBudget','=','b.prgBudget');
                                        $join->on('a.prgId','<','b.prgId');
                                    }
                                )
                                ->where('b.prgId',null)
                                ->where('a.prgProject',$prg->prgProject)
                                ->where('a.prgBudget',$prg->prgBudget)
                                ->first();
                
                $currEndate = Carbon::parse($currLastVal->prgEndPeriod);
                $newEndate = Carbon::parse($amp->ampEndExe);
                
                $diffInMonths = $newEndate->month - $currEndate->month;
                
                if($diffInMonths == 0){
                    $lastCrono = Progfisica::find($currLastVal->prgId);
                    $lastCrono->prgEndPeriod = $amp->ampEndExe;
                    $lastCrono->save();
                }
                else{
                    for($i = 0; $i <= $diffInMonths; $i++){
                        if($i == 0){
                            $lastCrono = Progfisica::find($currLastVal->prgId);
                            $lastCrono->prgEndPeriod = $currEndate->endOfMonth();
                            $lastCrono->save();
                        }
                        else{
                            $crono = new Progfisica();
                            $crono->prgProject = $currLastVal->prgProject;
                            $crono->prgBudget = $currLastVal->prgBudget;
                            $crono->prgExecutor = $currLastVal->prgExecutor;
                            $crono->prgNumberVal = $currLastVal->prgNumberVal + $i;
                            $crono->prgStartPeriod = $currEndate->copy()->addMonth($i)->startOfMonth();

                            if(($currEndate->month + $i) == $newEndate->month){
                                $crono->prgEndPeriod = $newEndate;
                                $crono->prgPeriodo = $newEndate->toDateString();
                            }
                            else{
                                $crono->prgEndPeriod = $currEndate->copy()->addMonth($i)->endOfMonth();
                                $crono->prgPeriodo = $currEndate->copy()->addMonth($i)->endOfMonth()->toDateString();
                            }

                            $crono->prgEditNote = 'Ampliación de plazo registrada';
                            $crono->save();
                        }
                    }
                }
            });

            if(is_null($exception)){
                $msg = 'Ampliación de plazo registrada correctamente \nCronograma calendarizado actualizado correctamente, ajuste los montos de la nueva programación.';
                $msgId = 200;
                $url = url('ver/programacion/0');
            }
            else{
                throw new Exception($exception);
            }

        }catch(Exception $e){
            $msg = 'Error: ' . $e->getMessage() . ' -Archivo- ' . $e->getFile() . ' -Linea- ' . $e->getLine() . " \n";
            $msgId = 500;
            $url= '';
        }

        return response()->json(compact('msg','msgId','url','pyId','ptId'));
    }
}