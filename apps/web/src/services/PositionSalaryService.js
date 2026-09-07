export const PositionSalaryService = {
  POSITIONS: {
    'Cashier': { department: 'Store Operations', basic_salary: 16000, hourly_rate: 125 },
    'Sales Associate': { department: 'Store Operations', basic_salary: 15000, hourly_rate: 125 },
    'Store Supervisor': { department: 'Store Operations', basic_salary: 22000, hourly_rate: 165 },
    'Store Manager': { department: 'Store Operations', basic_salary: 30000, hourly_rate: 230.77 },
    'Inventory Clerk': { department: 'Inventory & Warehouse', basic_salary: 16000, hourly_rate: 125 },
    'Stock Clerk': { department: 'Inventory & Warehouse', basic_salary: 15000, hourly_rate: 125 },
    'Warehouse Staff': { department: 'Inventory & Warehouse', basic_salary: 16000, hourly_rate: 125 },
    'Warehouse Supervisor': { department: 'Inventory & Warehouse', basic_salary: 23000, hourly_rate: 176.92 },
    'HR Assistant': { department: 'Administration & HR', basic_salary: 18000, hourly_rate: 138.46 },
    'HR Officer': { department: 'Administration & HR', basic_salary: 25000, hourly_rate: 192.31 },
    'Accounting Assistant': { department: 'Finance & Accounting', basic_salary: 18000, hourly_rate: 138.46 },
    'Accountant': { department: 'Finance & Accounting', basic_salary: 30000, hourly_rate: 230.77 },
    'Purchasing Assistant': { department: 'Purchasing', basic_salary: 18000, hourly_rate: 138.46 },
    'Purchasing Officer': { department: 'Purchasing', basic_salary: 25000, hourly_rate: 192.31 },
  },

  getDepartmentPositions(department) {
    return Object.entries(this.POSITIONS)
      .filter(([, v]) => v.department === department)
      .map(([name, v]) => ({ value: name, label: name, salary: v.basic_salary, hourlyRate: v.hourly_rate }))
  },

  getDepartmentNames() {
    return [...new Set(Object.values(this.POSITIONS).map(v => v.department))].sort()
  },

  getPositionSalary(position) {
    return this.POSITIONS[position]?.basic_salary ?? null
  },

  getPositionHourlyRate(position) {
    return this.POSITIONS[position]?.hourly_rate ?? null
  },

  getPositionDepartment(position) {
    return this.POSITIONS[position]?.department ?? null
  },

  isValidCombination(department, position) {
    return this.POSITIONS[position]?.department === department
  },
}
