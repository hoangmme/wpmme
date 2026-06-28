#!/bin/bash

# Lấy tham số đầu tiên làm commit message, nếu không có thì dùng "Update"
MSG=${1:-"Update"}

echo "🚀 Bắt đầu push code cho wpmme với message: '$MSG'"
echo "---------------------------------------------------------------"

# Thêm tất cả thay đổi
git add .

# Commit
git commit -m "$MSG"

# Push lên git
git push

echo "✅ Đã push wpmme thành công!"
