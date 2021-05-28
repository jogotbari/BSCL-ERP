<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

use App\Http\Requests\AssignStockRequest;
use App\Http\Requests\StockRequest;
use App\Imports\ImportStock;
use App\Models\Category;
use App\Models\Department;
use App\Models\Stock;
use App\Models\StockUser;
use App\User;
use Illuminate\Http\Request;
use Excel;
use Illuminate\Support\Facades\Auth;

class StockController extends Controller
{
    public function index(Request $request)
    {
        try {
            if (!Auth::user()->can('see stock list')) {
                return view('errors.403');
            }

            $categoryData = Category::where('type', 'Stock')->where('parent_id', 0)->with('nested')->get();
            $sql = Stock::orderBy('created_at', 'ASC');
            if ($request->location) {
                $sql->where('location', $request->location);
            }
            if ($request->category_id) {
                $sql->where('category_id', $request->category_id);
            }
            $stocks = $sql->paginate(10);
            return view('admin.stock.index', compact('stocks', 'categoryData'));
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function create()
    {
        if (!Auth::user()->can('add stock')) {
            return view('errors.403');
        }

        $categoryData = Category::where('type', 'Stock')->where('parent_id', 0)->with('nested')->get();
        return view('admin.stock.create-edit', compact('categoryData'));
    }

    public function store(StockRequest $request)
    {
        try {
            if (!Auth::user()->can('add stock')) {
                return view('errors.403');
            }

            $data = $request->all();
            Stock::create($data);
            return redirect()->route('admin.stocks.index', qArray())->withSuccess('Stock created successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function edit(Stock $stock)
    {
        if (!Auth::user()->can('edit stock')) {
            return view('errors.403');
        }

        if (empty($stock)) {
            return redirect()->route('admin.stocks.index');
        }
        $categoryData = Category::where('type', 'Stock')->where('parent_id', 0)->with('nested')->get();
        return view('admin.stock.create-edit', compact('categoryData', 'stock'))->with('edit', 1);
    }

    public function update(StockRequest $request, Stock $stock)
    {
        try {
            if (!Auth::user()->can('edit stock')) {
                return view('errors.403');
            }

            if (empty($stock)) {
                return redirect()->route('admin.stocks.index');
            }
            $data = $request->all();
            $stock->update($data);
            return redirect()->route('admin.stocks.index', qArray())->withSuccess('Stock updated successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function destroy(Stock $stock)
    {
        try {
            if (!Auth::user()->can('delete stock')) {
                return view('errors.403');
            }

            if (empty($stock)) {
                return redirect()->route('admin.stocks.index');
            }
            $stock->delete();
            return redirect()->route('admin.stocks.index', qArray())->withSuccess('Stock deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function assignedStock(Request $request)
    {
        try {

            if (!Auth::user()->can('see assigned stock list')) {
                return view('errors.403');
            }

            $categoryData = Category::where('parent_id', 0)->with('nested')->where('type', 'Stock')->get();
            $sql = StockUser::with('stock')->orderBy('created_at', 'ASC');

            if ($request->location) {
                $sql->whereHas('stock', function ($q) use ($request) {
                    $q->where('location', $request->location);
                });
            }
            if ($request->category_id) {
                $sql->whereHas('stock', function ($q) use ($request) {
                    $q->where('category_id', $request->category_id);
                });
            }
            if ($request->from) {
                $sql->whereDate('assign_date', '>=', $request->from);
            }
            if ($request->to) {
                $sql->whereDate('assign_date', '<=', $request->to);
            }
            $assignedStocks = $sql->paginate(10);
            return view('admin.stock.assigned-stock', compact('assignedStocks', 'categoryData'));
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function assignStockForm(Request $request, $id)
    {
        if (!Auth::user()->can('assign stock')) {
            return view('errors.403');
        }

        $data = Stock::where('id', $id)->first();
        $users = User::get();
        $departments = Department::get();
        return view('admin.stock.assign-stock', compact('data', 'users', 'departments'));
    }

    public function assignStock(AssignStockRequest $request, $id)
    {
        if (!Auth::user()->can('assign stock')) {
            return view('errors.403');
        }

        $stock_id = $id;
        $data = Stock::where('id', $stock_id)->first();

        if ($data->qty < $request->qty) {
            return redirect()->route('admin.stocks.index')->withErrors(['error' => 'Qty not in stock!']);
        }
        $updateData = [
            'qty' => $data->qty - $request->qty,
        ];
        $data->update($updateData);
        $storeData = [
            'qty' => $request->qty,
            'stock_id' => $request->stock_id,
            'assign_to' => $request->assign_to,
            'user_id' => $request->user_id,
            'dept_id' => $request->dept_id,
            'assign_date' => $request->assign_date,
        ];
        StockUser::create($storeData);
        return redirect()->route('admin.stocks.assigned-stock')->withSuccess('Stock Assigned successfully.');
    }

    public function summary()
    {
        try {

            if (!Auth::user()->can('see stock summary')) {
                return view('errors.403');
            }

            $categories = Category::with('nested')->where('parent_id', 0)->where('type', 'Stock')->paginate(50);
            return view('admin.stock.summary', compact('categories'));
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function categoryStocks(Request $request, $id)
    {
        $data['category'] = $category = Category::where('id', $id)->find($id);
        $sql = Stock::where('category_id', $category->id);
        if ($request->location) {
            $sql->where('location', $request->location);
        }
        $data['stocks'] = $sql->paginate(15);
        return view('admin.stock.summary-show', compact('data', 'category'));
    }

    public function ImportExcel(Request $request)
    {
        //Validation
        $this->validate($request, [
            'file' => 'required|mimes:xls,xlsx',
        ]);

        if ($request->hasFile('file')) {
            //UPLOAD FILE
            $file = $request->file('file'); //GET FILE
            Excel::import(new ImportStock(), $file); //IMPORT FILE
            return redirect()->back()->withSuccess('Upload file data Stock !');
        }

        return redirect()->back()->with(['error' => 'Please choose file before!']);
    }
}
